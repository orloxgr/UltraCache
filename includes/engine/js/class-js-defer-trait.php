<?php
/**
 * JavaScript defer and parallel-execution helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_JS_Defer_Trait
{

    public function defer_scripts($tag, $handle, $src)
    {
        $settings = $this->get_settings();
        if (is_admin()) {
            return $tag;
        }

        if ($this->is_ultracache_frontend_js_helper_handle($handle)) {
            return $this->strip_native_loading_attributes_from_script_tag($tag);
        }

        if ($this->is_js_excluded_by_user_patterns($handle, $src, $tag, '', $settings)) {
            return $this->strip_native_loading_attributes_from_script_tag($tag);
        }

        $defer_stage = $this->get_defer_stage_level($settings);
        $defer_all_js = !empty($settings['defer_all_js']);
        $delay_all_js = !empty($settings['delay_all_js']);

        if (!$defer_all_js && 0 < $defer_stage && $this->is_script_absolute_defer_blocking($handle, $src, $tag, $settings)) {
            return $this->strip_native_loading_attributes_from_script_tag($tag);
        }

        if (0 < $defer_stage && $this->is_script_user_defer_excluded($handle, $src, $settings, $tag)) {
            return $this->strip_native_loading_attributes_from_script_tag($tag);
        }

        if (!$defer_all_js && $this->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
            return $this->add_defer_or_parallel_attribute_to_script_tag($tag, $src, $settings, true);
        }

        /*
         * Avoid splitting WordPress script groups at script_loader_tag time.
         * If a handle has registered before/after/extra/translation inline
         * companions, leave the external tag untouched here so later HTML
         * passes can either keep or delay the whole group consistently.
         */
        if ($this->script_handle_has_wp_inline_companion_segments($handle)) {
            return $this->strip_native_loading_attributes_from_script_tag($tag);
        }

        /*
         * Delay-all final HTML processing owns the full ordered decision.
         * Do not emit native defer while that delayed-loader pass is active,
         * because that would create mixed defer/delay execution classes.
         */
        if ($delay_all_js && $this->is_defer_all_js_candidate($handle, $src, $tag, $settings)) {
            return $tag;
        }

        if (!$defer_all_js && 0 < $defer_stage && $this->is_script_force_blocking($handle, $src, $tag, $settings)) {
            return $this->strip_native_loading_attributes_from_script_tag($tag);
        }

        if (!$defer_all_js && 0 < $defer_stage && $this->is_script_safe_stage_excluded($handle, $src, $tag, $settings)) {
            return $this->strip_native_loading_attributes_from_script_tag($tag);
        }

        if (2 <= $defer_stage) {
            $third_party_delay_match = $this->get_third_party_delay_match($handle, $src, $tag, $settings);
            if (!empty($third_party_delay_match['matched'])) {
                return $this->build_delayed_script_tag($tag, $handle, $src, $third_party_delay_match['reason']);
            }
        }

        if (2 <= $defer_stage && !empty($settings['delay_non_critical_js']) && $this->should_delay_non_critical_script($handle, $src, $tag, $settings)) {
            return $this->build_delayed_script_tag($tag, $handle, $src);
        }

        if (!empty($settings['async_external_scripts']) && $this->should_async_external_script($handle, $src, $tag, $settings)) {
            return $this->add_async_attribute_to_script_tag($tag);
        }

        if (0 === $defer_stage || (empty($settings['defer_js']) && !$defer_all_js)) {
            return $tag;
        }

        if ($delay_all_js) {
            return $tag;
        }

        return $this->add_defer_or_parallel_attribute_to_script_tag($tag, $src, $settings, false);
    }



    private function should_keep_script_blocking_for_defer_all($handle, $src, $tag = '', array $settings = array())
    {
        // Defer all JS is intentionally literal/aggressive: the only scripts
        // kept blocking are those matching the visible Do Not Defer or Delay
        // fallback field. WordPress/core/slider protections belong in that
        // editable list via Populate Defaults, not in hidden runtime rules.
        return $this->is_script_user_defer_excluded($handle, $src, $settings, $tag);
    }



    private function should_native_defer_all_local_script($src, array $settings = array())
    {
        /*
         * 2.56.122 regression guard: 2.56.120 bypassed the ordered
         * delayed-loader for every same-host script when Defer all JS was
         * enabled. That broke grouped inline-before / inline-after config
         * scripts for Complianz, Site Kit, WooCommerce and similar assets.
         * Keep this helper as a no-op so the dependency-aware ordered path
         * remains authoritative.
         */
        return false;
    }



    private function apply_defer_all_js_to_html($html, array $settings = array())
    {
        if (empty($settings['delay_all_js']) || !is_string($html) || '' === $html || false === stripos($html, '<script')) {
            return $html;
        }

        $records = $this->collect_script_dependency_records_from_html($html);
        if (empty($records)) {
            return $html;
        }

        $protected_groups = $this->get_user_excluded_script_dependency_groups($records, $settings);
        $protected_indexes = $this->get_user_excluded_script_dependency_indexes($records, $settings);
        $force_defer_groups = $this->get_user_force_deferred_script_dependency_groups($records, $settings);
        $replacements = array();

        foreach ($records as $index => $record) {
            if (empty($record['tag']) || empty($record['open'])) {
                continue;
            }

            $handle = isset($record['handle']) ? (string) $record['handle'] : '';
            $src = isset($record['src']) ? (string) $record['src'] : '';
            $group = isset($record['group']) ? (string) $record['group'] : '';
            $force_defer = $this->script_record_matches_user_force_defer($record, $settings) || ('' !== $group && !empty($force_defer_groups[$group]));

            if ($this->is_ultracache_frontend_js_helper_record($record)) {
                continue;
            }

            if (isset($protected_indexes[(int) $index]) || $this->script_record_matches_user_defer_exclusion($record, $settings) || ('' !== $group && !empty($protected_groups[$group]))) {
                continue;
            }

            $source_tag = !empty($record['delayed']) ? $this->restore_delayed_script_record_tag($record) : (string) $record['tag'];
            if (!is_string($source_tag) || '' === $source_tag) {
                $source_tag = (string) $record['tag'];
            }

            if (!empty($record['has_src'])) {
                if ('' === $src) {
                    continue;
                }
                if ($force_defer) {
                    $deferred = $this->add_defer_or_parallel_attribute_to_script_tag($source_tag, $src, $settings, true);
                    if (is_string($deferred) && '' !== $deferred && $deferred !== (string) $record['tag']) {
                        $replacements[(int) $index] = $deferred;
                    }
                    continue;
                }
                $replacements[(int) $index] = $this->build_delayed_script_tag($source_tag, $handle, $src, 'all-js');
                continue;
            }

            if (!$this->is_delayable_inline_script_tag($source_tag)) {
                continue;
            }

            if ($force_defer) {
                $externalized = $this->build_deferred_external_inline_script_tag($record, $settings);
                if (is_string($externalized) && '' !== $externalized && $externalized !== (string) $record['tag']) {
                    $replacements[(int) $index] = $externalized;
                }
                continue;
            }

            $replacements[(int) $index] = $this->build_delayed_inline_script_tag($source_tag, $handle, 'all-js');
        }

        if (empty($replacements)) {
            return $html;
        }

        ksort($replacements);
        $processed = $this->apply_delayed_script_replacements_with_processor($html, $records, $replacements);
        return is_string($processed) ? $processed : $html;
    }



    private function apply_native_defer_all_js_to_html($html, array $settings = array())
    {
        if (empty($settings['defer_all_js']) || !empty($settings['delay_all_js']) || !is_string($html) || '' === $html || false === stripos($html, '<script')) {
            return $html;
        }

        $records = $this->collect_script_dependency_records_from_html($html);
        if (empty($records)) {
            return $html;
        }

        $replacements = array();
        foreach ($records as $index => $record) {
            if (empty($record['tag']) || empty($record['open'])) {
                continue;
            }

            if (!empty($record['delayed'])) {
                continue;
            }

            $tag = (string) $record['tag'];
            $open = (string) $record['open'];
            $handle = isset($record['handle']) ? (string) $record['handle'] : '';
            $src = isset($record['src']) ? (string) $record['src'] : '';

            if ($this->is_ultracache_frontend_js_helper_record($record)) {
                continue;
            }

            if ($this->script_record_matches_user_defer_exclusion($record, $settings)) {
                continue;
            }

            if (!empty($record['has_src'])) {
                if (!$this->is_defer_all_js_candidate($handle, $src, $open, $settings)) {
                    continue;
                }
                $deferred = $this->add_defer_or_parallel_attribute_to_script_tag($tag, $src, $settings, true);
                if (is_string($deferred) && '' !== $deferred && $deferred !== $tag) {
                    $replacements[(int) $index] = $deferred;
                }
                continue;
            }

            if (!$this->is_delayable_inline_script_tag($tag)) {
                continue;
            }

            $externalized = $this->build_deferred_external_inline_script_tag($record, $settings);
            if (is_string($externalized) && '' !== $externalized && $externalized !== $tag) {
                $replacements[(int) $index] = $externalized;
            }
        }

        if (empty($replacements)) {
            return $html;
        }

        ksort($replacements);
        $out = '';
        $last = 0;
        foreach ($replacements as $index => $replacement) {
            if (!isset($records[$index])) {
                continue;
            }
            $record = $records[$index];
            $offset = isset($record['offset']) ? (int) $record['offset'] : -1;
            $tag = isset($record['tag']) ? (string) $record['tag'] : '';
            if ($offset < 0 || '' === $tag) {
                continue;
            }
            $out .= substr($html, $last, $offset - $last) . $replacement;
            $last = $offset + strlen($tag);
        }

        return $out . substr($html, $last);
    }



    private function build_deferred_external_inline_script_tag(array $record, array $settings = array())
    {
        $tag = isset($record['tag']) ? (string) $record['tag'] : '';
        if ('' === $tag || !preg_match('/^<script\b[^>]*>(.*?)<\/script>$/is', $tag, $content_match)) {
            return $tag;
        }

        $content = isset($content_match[1]) ? (string) $content_match[1] : '';
        if ('' === trim($content)) {
            return $tag;
        }

        $asset = $this->write_deferred_inline_js_asset($content, $record);
        if (empty($asset['url'])) {
            return $tag;
        }

        $original_attributes = $this->extract_html_tag_attributes($tag);
        $attrs = array();
        foreach ($original_attributes as $name => $value) {
            $name_lc = strtolower((string) $name);
            if (in_array($name_lc, array('src', 'async', 'defer', 'type', 'data-wp-strategy'), true)) {
                continue;
            }
            if (0 === strpos($name_lc, 'data-ultracache-')) {
                continue;
            }
            if (!preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $name_lc)) {
                continue;
            }
            if (is_scalar($value)) {
                $attrs[$name_lc] = (string) $value;
            }
        }

        $attrs['src'] = (string) $asset['url'];
        if ($this->should_parallelize_deferred_script((string) $asset['url'], $settings)) {
            $attrs['async'] = true;
        } else {
            $attrs['defer'] = true;
        }
        $attrs['data-ultracache-deferred-inline'] = '1';
        if (!empty($asset['hash'])) {
            $attrs['data-ultracache-deferred-inline-hash'] = (string) $asset['hash'];
        }

        // This replaces an already-rendered inline script during final HTML optimization, after the enqueue phase.
        // Use the WordPress script-tag API so attributes are filtered and serialized by core instead of manual markup.
        return rtrim(wp_get_script_tag($attrs), "\r\n");
    }



    private function write_deferred_inline_js_asset($content, array $record = array())
    {
        $content = (string) $content;
        if ('' === trim($content) || !defined('ULTRACACHE_CACHE_DIR')) {
            return array();
        }

        $hash = substr(hash('sha256', $content), 0, 32);
        $handle = isset($record['handle']) ? sanitize_key((string) $record['handle']) : '';
        if ('' === $handle) {
            $handle = 'inline';
        }
        $filename = 'defer-' . $handle . '-' . $hash . '.js';
        $dir = ultracache_generated_asset_dir('deferred-inline-js');
        $file = $dir . $filename;

        if (!is_dir($dir) && function_exists('wp_mkdir_p')) {
            wp_mkdir_p($dir);
        }
        if (is_dir($dir)) {
            $index = $dir . 'index.php';
            if (!is_file($index)) {
                ultracache_safe_file_put_contents($index, "<?php\n// Silence is golden.\n", 0, 'deferred_inline_js_index');
            }
        }

        if (!is_file($file)) {
            $payload = $content;
            if ('' !== $payload && "\n" !== substr($payload, -1)) {
                $payload .= "\n";
            }
            $written = ultracache_safe_file_put_contents($file, $payload, LOCK_EX, 'deferred_inline_js_asset');
            if (false === $written) {
                return array();
            }
        }

        return array(
            'hash' => $hash,
            'path' => $file,
            'url'  => ultracache_generated_asset_url('deferred-inline-js', $filename),
        );
    }



    private function apply_script_loading_attribute_mutations_with_processor($html, array $mutations)
    {
        if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || empty($mutations)) {
            return null;
        }

        try {
            $processor = new WP_HTML_Tag_Processor($html);
            $index = 0;
            $changed = false;

            while ($processor->next_tag('SCRIPT')) {
                if (!isset($mutations[$index])) {
                    $index++;
                    continue;
                }

                $action = (string) $mutations[$index];
                if ('strip-loading' === $action) {
                    $processor->remove_attribute('async');
                    $processor->remove_attribute('defer');
                    $processor->remove_attribute('data-wp-strategy');
                    $changed = true;
                } elseif ('defer' === $action) {
                    $processor->remove_attribute('async');
                    $processor->remove_attribute('data-wp-strategy');
                    $processor->set_attribute('defer', 'defer');
                    $changed = true;
                } elseif ('async' === $action) {
                    $processor->remove_attribute('defer');
                    $processor->remove_attribute('data-wp-strategy');
                    $processor->set_attribute('async', 'async');
                    $changed = true;
                }

                $index++;
            }

            if (!$changed) {
                return null;
            }

            $updated = $processor->get_updated_html();
            return is_string($updated) && '' !== $updated ? $updated : null;
        } catch (\Throwable $e) {
            return null;
        }
    }



    private function get_defer_stage_level(array $settings = array())
    {
        if (!empty($settings['defer_stage_aggressive']) || !empty($settings['delay_non_critical_js_aggressive'])) {
            return 3;
        }

        if (!empty($settings['defer_stage_balanced'])) {
            return 2;
        }

        if (!empty($settings['defer_stage_safe']) || !empty($settings['defer_js'])) {
            return 1;
        }

        return 0;
    }



    private function should_parallelize_deferred_script($src, array $settings = array())
    {
        $src = trim((string) $src);
        if ('' === $src) {
            return false;
        }

        if ($this->is_third_party_script_src($src)) {
            return !empty($settings['third_party_js_parallel_execution']);
        }

        return !empty($settings['first_party_js_parallel_execution']);
    }



    private function add_defer_or_parallel_attribute_to_script_tag($tag, $src, array $settings = array(), $force = false)
    {
        if ($this->should_parallelize_deferred_script($src, $settings)) {
            return $this->add_async_attribute_to_script_tag($tag);
        }

        return $this->add_defer_attribute_to_script_tag($tag, $force);
    }



    private function add_defer_attribute_to_script_tag($tag, $force = false)
    {
        $tag = (string) $tag;
        if ('' === $tag || false === stripos($tag, '<script') || false === stripos($tag, ' src=')) {
            return $tag;
        }

        if (!$force && (false !== stripos($tag, ' defer') || false !== stripos($tag, ' async') || false !== stripos($tag, ' type="module"'))) {
            return $tag;
        }

        if ($force) {
            $tag = $this->remove_html_tag_attribute($tag, 'async');
            $tag = $this->remove_html_tag_attribute($tag, 'data-wp-strategy');
            $tag = preg_replace('/\s{2,}/', ' ', $tag);
        }

        if (false !== stripos($tag, ' defer')) {
            return $tag;
        }

        return $this->set_or_add_html_tag_attribute($tag, 'defer', 'defer');
    }



    private function add_async_attribute_to_script_tag($tag)
    {
        $tag = (string) $tag;
        if ('' === $tag) {
            return $tag;
        }

        if (false !== stripos($tag, ' async') || false !== stripos($tag, ' type="module"') || false !== stripos($tag, ' nomodule')) {
            return $tag;
        }

        if (false !== stripos($tag, ' defer')) {
            $tag = $this->remove_html_tag_attribute($tag, 'defer');
        }

        $tag = $this->remove_html_tag_attribute($tag, 'data-wp-strategy');

        return $this->set_or_add_html_tag_attribute($tag, 'async', 'async');
    }



    private function should_async_external_script($handle, $src, $tag, array $settings = array())
    {
        $src = trim((string) $src);
        if ('' === $src || false === stripos((string) $tag, '<script')) {
            return false;
        }

        if (false !== stripos((string) $tag, ' async') || false !== stripos((string) $tag, ' type="module"') || false !== stripos((string) $tag, ' nomodule')) {
            return false;
        }

        $src_host = (string) wp_parse_url($src, PHP_URL_HOST);
        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ('' === $src_host || '' === $home_host || strtolower($src_host) === strtolower($home_host)) {
            return false;
        }

        $haystack = strtolower((string) $handle . ' ' . $src . ' ' . $tag);
        /*
         * Matching list only: used to add async to already-enqueued
         * external scripts. It does not add external services to the site.
         */
        $patterns = array(
            'googletagmanager.com',
            'google-analytics.com',
            'googleanalytics.com',
            'gtag/js',
            'googleadservices.com',
            'g.doubleclick.net',
            'connect.facebook.net',
            'facebook.com/tr',
            'bat.bing.com',
            'clarity.ms',
            'usefathom.com',
            'plausible.io',
            'analytics.tiktok.com',
            'static.hotjar.com',
            'script.hotjar.com',
            'snap.licdn.com',
            'px.ads.linkedin.com',
            'pinimg.com/ct/',
            'redditstatic.com/ads/',
            'mc.yandex.ru',
        );

        foreach ($patterns as $pattern) {
            if (false !== strpos($haystack, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }



    private function is_defer_all_js_candidate($handle, $src, $tag = '', array $settings = array())
    {
        $src = trim((string) $src);
        $tag = (string) $tag;

        if ('' === $src || false === stripos($tag, '<script')) {
            return false;
        }

        if (false !== stripos($tag, ' async') || false !== stripos($tag, ' type="module"') || false !== stripos($tag, " type='module'") || false !== stripos($tag, ' nomodule')) {
            return false;
        }

        if (false !== stripos($tag, ' defer')) {
            return false;
        }

        return true;
    }

}
