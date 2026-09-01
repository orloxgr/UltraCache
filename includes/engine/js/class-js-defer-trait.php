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

    /**
     * Add diagnostic-only WordPress handle/dependency metadata to printed scripts.
     *
     * The metadata is emitted only for the internal JS inventory loopback request,
     * so normal frontend HTML is unchanged. This lets the Console Error Handler
     * compare UltraCache's effective Delay/Defer strategy with WordPress' declared
     * dependency graph instead of inferring dependencies from filenames.
     *
     * @param string $tag    Rendered script tag.
     * @param string $handle WordPress script handle.
     * @param string $src    Script source URL.
     * @return string
     */
    public function annotate_runtime_js_inventory_script_tag($tag, $handle, $src)
    {
        unset($src);

        if (is_admin() || '1' !== sanitize_text_field(ultracache_query_value('ultracache_js_inventory'))) {
            return $tag;
        }

        $handle = sanitize_key((string) $handle);
        if ('' === $handle || !class_exists('WP_HTML_Tag_Processor')) {
            return $tag;
        }

        $deps = array();
        if (function_exists('wp_scripts')) {
            $wp_scripts = wp_scripts();
            if (is_object($wp_scripts) && !empty($wp_scripts->registered[$handle]) && is_object($wp_scripts->registered[$handle])) {
                foreach ((array) ($wp_scripts->registered[$handle]->deps ?? array()) as $dependency) {
                    $dependency = sanitize_key((string) $dependency);
                    if ('' !== $dependency) {
                        $deps[$dependency] = true;
                    }
                }
            }
        }

        try {
            $processor = new WP_HTML_Tag_Processor((string) $tag);
            if (!$processor->next_tag('SCRIPT')) {
                return $tag;
            }
            $processor->set_attribute('data-ultracache-handle', $handle);
            if (!empty($deps)) {
                $processor->set_attribute('data-ultracache-deps', implode(',', array_keys($deps)));
            }
            $updated = $processor->get_updated_html();
            return is_string($updated) && '' !== $updated ? $updated : $tag;
        } catch (\Throwable $e) {
            return $tag;
        }
    }

    /**
     * Attach an opaque parser-early helper configuration to the external script.
     *
     * The payload is base64url JSON produced server-side. Keeping tracker names
     * out of executable inline JavaScript prevents CMP content classifiers from
     * disarming UltraCache's bootstrap configuration as marketing code.
     *
     * @param string $tag            Rendered script tag.
     * @param string $opaque_config  Base64url-encoded JSON payload.
     * @return string
     */
    private function add_ultracache_opaque_config_to_script_tag($tag, $opaque_config)
    {
        $opaque_config = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $opaque_config);
        if ('' === $opaque_config) {
            return $tag;
        }

        if (class_exists('WP_HTML_Tag_Processor')) {
            try {
                $processor = new WP_HTML_Tag_Processor((string) $tag);
                if ($processor->next_tag('SCRIPT')) {
                    $processor->set_attribute('data-ultracache-config', $opaque_config);
                    $updated = $processor->get_updated_html();
                    if (is_string($updated) && '' !== $updated) {
                        return $updated;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to the conservative string insertion below.
            }
        }

        $attribute = ' data-ultracache-config="' . esc_attr($opaque_config) . '"';
        $updated = preg_replace('/<script\b/i', '<script' . $attribute, (string) $tag, 1);
        return is_string($updated) && '' !== $updated ? $updated : $tag;
    }

    public function defer_scripts($tag, $handle, $src)
    {
        $settings = $this->get_settings();
        if (is_admin() || (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations())) {
            return $tag;
        }

        $route = $this->ultracache_build_registered_script_route($tag, $handle, $src, $settings);
        return $this->ultracache_apply_registered_script_route($route, $tag, $handle, $src, $settings);
    }



    private function should_keep_script_blocking_for_defer_all($handle, $src, $tag = '', array $settings = array())
    {

        if ($this->is_script_force_blocking($handle, $src, $tag, $settings)) {
            return true;
        }

        if (method_exists($this, 'should_protect_woocommerce_variable_product_interaction_script')
            && $this->should_protect_woocommerce_variable_product_interaction_script($handle, $src, $tag, $settings)) {
            return true;
        }

        // Defer all JS is intentionally literal/aggressive: other scripts
        // kept blocking are those matching the visible Do Not Defer or Delay
        // fallback field.
        return $this->is_script_user_defer_excluded($handle, $src, $settings, $tag);
    }



    private function should_native_defer_all_local_script($src, array $settings = array())
    {
        /*
         * 2.56.122 regression guard: 2.56.120 bypassed the ordered
         * delayed-loader for every same-host script when Defer all JS was
         * enabled. That broke grouped inline-before / inline-after config
         * scripts with WordPress inline-before / inline-after companions.
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
        $wpbakery_force_defer_groups = array();
        $elementor_force_defer_groups = array();
        $optimizer_opt_out_groups = array();
        foreach ($records as $record) {
            $record_group = isset($record['group']) ? (string) $record['group'] : '';
            if ('' === $record_group) {
                continue;
            }
            $record_handle = isset($record['handle']) ? (string) $record['handle'] : '';
            $record_src = isset($record['src']) ? (string) $record['src'] : '';
            $record_tag = (string) ($record['tag'] ?? '');
            // Explicit optimizer opt-outs remain group-wide interoperability
            // contracts for this legacy dependency-group pass. Vendor/CMP identity
            // does not participate in scheduling here.
            if ($this->is_script_tag_optimizer_opted_out($record_tag)) {
                $optimizer_opt_out_groups[$record_group] = true;
            }
            if (empty($record['has_src'])) {
                continue;
            }
            if ($this->should_protect_wpbakery_animation_script($record_handle, $record_src, $record_tag, $settings)) {
                $wpbakery_force_defer_groups[$record_group] = true;
            }
            if ($this->should_protect_elementor_compatibility_script($record_handle, $record_src, $record_tag, $settings)) {
                $elementor_force_defer_groups[$record_group] = true;
            }
        }
        $replacements = array();

        foreach ($records as $index => $record) {
            if (empty($record['tag']) || empty($record['open'])) {
                continue;
            }

            $handle = isset($record['handle']) ? (string) $record['handle'] : '';
            $src = isset($record['src']) ? (string) $record['src'] : '';
            $group = isset($record['group']) ? (string) $record['group'] : '';
            $optimizer_opted_out = $this->is_script_tag_optimizer_opted_out((string) $record['tag'])
                || ('' !== $group && !empty($optimizer_opt_out_groups[$group]));
            $elementor_force_defer = $this->should_protect_elementor_compatibility_script($handle, $src, (string) $record['tag'], $settings)
                || ('' !== $group && !empty($elementor_force_defer_groups[$group]));
            $force_defer = $elementor_force_defer
                || $this->script_record_matches_user_force_defer($record, $settings)
                || $this->should_protect_wpbakery_animation_script($handle, $src, (string) $record['tag'], $settings)
                || ('' !== $group && (!empty($force_defer_groups[$group]) || !empty($wpbakery_force_defer_groups[$group])));

            if ($this->is_ultracache_frontend_js_helper_record($record)) {
                continue;
            }

            $source_tag = !empty($record['delayed']) ? $this->restore_delayed_script_record_tag($record) : (string) $record['tag'];
            if (!is_string($source_tag) || '' === $source_tag) {
                $source_tag = (string) $record['tag'];
            }

            if ($optimizer_opted_out) {
                continue;
            }

            if (isset($protected_indexes[(int) $index]) || $this->script_record_matches_user_defer_exclusion($record, $settings) || ('' !== $group && !empty($protected_groups[$group]))) {
                continue;
            }

            if (!empty($record['has_src'])) {
                if ('' === $src) {
                    continue;
                }
                if ($force_defer) {
                    $deferred = $elementor_force_defer
                        ? $this->add_defer_attribute_to_script_tag($source_tag, true)
                        : $this->add_defer_or_parallel_attribute_to_script_tag($source_tag, $src, $settings, true);
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
                $externalized = $this->build_deferred_external_inline_script_tag($record, $settings, $elementor_force_defer);
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



    private function build_deferred_external_inline_script_tag(array $record, array $settings = array(), $force_ordered_defer = false)
    {
        unset($settings, $force_ordered_defer);

        $tag = isset($record['tag']) ? (string) $record['tag'] : '';
        if ('' === $tag || !preg_match('/^<script\\b[^>]*>(.*?)<\\/script>$/is', $tag, $content_match)) {
            return $tag;
        }

        $content = isset($content_match[1]) ? (string) $content_match[1] : '';
        if ('' === trim($content)) {
            return $tag;
        }

        /*
         * 3.12.36: DEFER inline JavaScript is no longer externalized into one
         * generated file per occurrence. Keep the exact source inline during
         * server-side lane normalization, mark it as a DEFER candidate, and let
         * the final Unified Inline Registry pass collect the source once into the
         * page manifest. This intermediate tag is never sent to the browser.
         */
        $original_attributes = $this->extract_html_tag_attributes($tag);
        $attrs = array();
        foreach ($original_attributes as $name => $value) {
            $name_lc = strtolower((string) $name);
            if (in_array($name_lc, array('src', 'async', 'defer', 'data-wp-strategy'), true)) {
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

        $attrs['defer'] = true;
        $attrs['data-ultracache-inline-defer-candidate'] = '1';
        $attrs['data-ultracache-inline-defer-hash'] = substr(hash('sha256', $content), 0, 32);

        return rtrim(wp_get_inline_script_tag($content, $attrs), "\r\n");
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

        if ($this->should_protect_elementor_compatibility_script('', $src, '', $settings)) {
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
        if ('' === $src_host) {
            return false;
        }
        if (function_exists('ultracache_is_public_site_url')) {
            if (ultracache_is_public_site_url($src)) {
                return false;
            }
        } else {
            $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ('' === $home_host || strtolower($src_host) === strtolower($home_host)) {
                return false;
            }
        }

        /*
         * Async external transport has no private vendor allowlist. When the
         * legacy switch is enabled it reuses the same user-visible third-party
         * pattern list shown as Delay third-party JS Patterns.
         */
        $patterns = $this->get_safe_third_party_delay_patterns($settings);
        return '' !== $this->get_matching_third_party_delay_pattern($handle, $src, $tag, $patterns);
    }


    private function is_defer_all_js_candidate($handle, $src, $tag = '', array $settings = array())
    {
        $src = trim((string) $src);
        $tag = (string) $tag;

        if ('' === $src || false === stripos($tag, '<script')) {
            return false;
        }

        if ($this->is_script_tag_optimizer_opted_out($tag)) {
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
