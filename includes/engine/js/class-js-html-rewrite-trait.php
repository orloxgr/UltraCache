<?php
/**
 * JavaScript HTML tag parsing and rewrite helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_JS_HTML_Rewrite_Trait
{

    private function restore_user_excluded_delayed_scripts_in_html($html, array $settings = array())
    {
        if (!is_string($html) || '' === $html || false === stripos($html, 'text/ultracache-delayed-js')) {
            return $html;
        }

        $records = $this->collect_script_dependency_records_from_html($html);
        if (empty($records)) {
            return $html;
        }

        $protected_groups = $this->get_user_excluded_script_dependency_groups($records, $settings);
        $protected_indexes = $this->get_user_excluded_script_dependency_indexes($records, $settings);
        $force_defer_groups = $this->get_user_force_deferred_script_dependency_groups($records, $settings);
        $elementor_force_defer_groups = array();
        foreach ($records as $record) {
            if (empty($record['has_src'])) {
                continue;
            }
            $group = isset($record['group']) ? (string) $record['group'] : '';
            if ('' === $group) {
                continue;
            }
            if ($this->should_protect_elementor_compatibility_script(
                isset($record['handle']) ? (string) $record['handle'] : '',
                isset($record['src']) ? (string) $record['src'] : '',
                isset($record['open']) ? (string) $record['open'] : '',
                $settings
            )) {
                $elementor_force_defer_groups[$group] = true;
            }
        }
        $replacements = array();

        foreach ($records as $index => $record) {
            if (empty($record['delayed']) || empty($record['tag'])) {
                continue;
            }

            $group = isset($record['group']) ? (string) $record['group'] : '';
            $elementor_force_defer = $this->should_protect_elementor_compatibility_script(
                isset($record['handle']) ? (string) $record['handle'] : '',
                isset($record['src']) ? (string) $record['src'] : '',
                isset($record['open']) ? (string) $record['open'] : '',
                $settings
            ) || ('' !== $group && !empty($elementor_force_defer_groups[$group]));
            $user_native = isset($protected_indexes[(int) $index])
                || $this->script_record_matches_user_defer_exclusion($record, $settings)
                || ('' !== $group && !empty($protected_groups[$group]));
            $user_force_defer = !$user_native && ($this->script_record_matches_user_force_defer($record, $settings) || ('' !== $group && !empty($force_defer_groups[$group])));
            $force_defer = !$user_native && ($elementor_force_defer || $user_force_defer);
            if (!$user_native && !$force_defer) {
                continue;
            }

            $restored = $this->restore_delayed_script_record_tag($record);
            if ($force_defer && !empty($record['has_src'])) {
                $restored = $elementor_force_defer
                    ? $this->add_defer_attribute_to_script_tag($restored, true)
                    : $this->add_defer_or_parallel_attribute_to_script_tag($restored, isset($record['src']) ? (string) $record['src'] : '', $settings, true);
            } elseif ($force_defer && $this->is_delayable_inline_script_tag($restored)) {
                $externalized = $this->build_deferred_external_inline_script_tag($record, $settings, $elementor_force_defer);
                if (is_string($externalized) && '' !== $externalized) {
                    $restored = $externalized;
                }
            }
            if (is_string($restored) && '' !== $restored && $restored !== $record['tag']) {
                $replacements[(int) $index] = $restored;
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



    private function restore_delayed_script_record_tag(array $record)
    {
        $tag = isset($record['tag']) ? (string) $record['tag'] : '';
        if ('' === $tag || false === stripos($tag, 'text/ultracache-delayed-js')) {
            return $tag;
        }

        if (!preg_match('/^<script\b[^>]*>(.*?)<\/script>$/is', $tag, $content_match) || !preg_match('/^<script\b[^>]*>/i', $tag, $open_match)) {
            return $tag;
        }

        $open = (string) $open_match[0];
        $content = isset($content_match[1]) ? (string) $content_match[1] : '';
        $attrs = $this->extract_html_tag_attributes($open);
        $preserved = $this->decode_delayed_script_preserved_attributes($attrs);

        foreach (array('id', 'nonce', 'crossorigin', 'referrerpolicy', 'integrity') as $attr) {
            $data_key = 'data-ultracache-' . $attr;
            if (!isset($preserved[$attr]) && isset($attrs[$data_key]) && '' !== $attrs[$data_key]) {
                $preserved[$attr] = (string) $attrs[$data_key];
            }
        }

        $is_inline = !empty($record['inline_delayed']) || (isset($attrs['data-ultracache-inline']) && '1' === (string) $attrs['data-ultracache-inline']);
        if (!$is_inline) {
            $src = isset($record['src']) ? (string) $record['src'] : '';
            if ('' === $src && isset($attrs['data-ultracache-src'])) {
                $src = (string) $attrs['data-ultracache-src'];
            }
            if ('' === $src && isset($attrs['data-ultracache-original-src'])) {
                $src = (string) $attrs['data-ultracache-original-src'];
            }
            if ('' === $src) {
                return $tag;
            }
            $preserved['src'] = $src;
        } else {
            unset($preserved['src']);
        }

        $diagnostic_dependency_metadata = '';
        if ('1' === sanitize_text_field(ultracache_query_value('ultracache_js_inventory')) && isset($attrs['data-ultracache-deps'])) {
            $diagnostic_dependency_metadata = preg_replace('/[^a-z0-9_,-]+/i', '', (string) $attrs['data-ultracache-deps']);
            if (!is_string($diagnostic_dependency_metadata)) {
                $diagnostic_dependency_metadata = '';
            }
        }

        unset($preserved['type'], $preserved['async'], $preserved['defer'], $preserved['data-wp-strategy']);
        foreach (array_keys($preserved) as $name) {
            if (0 === strpos(strtolower((string) $name), 'data-ultracache-')) {
                unset($preserved[$name]);
            }
        }
        if ('' !== $diagnostic_dependency_metadata) {
            $preserved['data-ultracache-deps'] = $diagnostic_dependency_metadata;
        }

        $script_attributes = array();
        foreach ($preserved as $name => $value) {
            $name = strtolower(trim((string) $name));
            if ('' === $name || !preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $name)) {
                continue;
            }
            $script_attributes[$name] = true === $value || $value === $name ? true : (string) $value;
        }

        // This restores a script from an UltraCache delayed-script placeholder in final rendered HTML.
        // The source tag was already printed before optimization, so core's tag APIs are used instead of manual markup.
        if ($is_inline) {
            return rtrim(wp_get_inline_script_tag($content, $script_attributes), "\r\n");
        }

        return rtrim(wp_get_script_tag($script_attributes), "\r\n");
    }



    private function decode_delayed_script_preserved_attributes(array $attrs)
    {
        $encoded = isset($attrs['data-ultracache-attrs']) ? (string) $attrs['data-ultracache-attrs'] : '';
        if ('' === $encoded) {
            return array();
        }

        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || '' === $decoded) {
            return array();
        }

        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            return array();
        }

        $out = array();
        foreach ($json as $name => $value) {
            $name = strtolower(trim((string) $name));
            if ('' === $name || 0 === strpos($name, 'data-ultracache-')) {
                continue;
            }
            if (is_scalar($value)) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }



    /**
     * Recover the original WordPress script id from a consent-controlled
     * placeholder when the CMP replaced the live DOM id. This is provenance
     * only: it restores family topology, it does not classify a vendor or
     * change consent ownership.
     */
    private function ultracache_consent_original_script_id_from_tag($tag)
    {
        $tag = (string) $tag;
        if ('' === $tag || false === stripos($tag, '<script')) {
            return '';
        }

        $original_id = html_entity_decode(
            (string) $this->extract_attribute_from_html_tag($tag, 'consent-original-id-_'),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $original_id = trim($original_id);
        if ('' === $original_id) {
            return '';
        }

        // Only WordPress script-family companion ids are topology evidence.
        if (!preg_match('/-js-(?:extra|before|after|translations)$/i', $original_id)) {
            return '';
        }

        return $original_id;
    }



    private function collect_script_dependency_records_from_html($html)
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
            return array();
        }

        if (!preg_match_all('/<script\b[^>]*>.*?<\/script>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return array();
        }

        $records = array();
        foreach ($matches as $index => $match) {
            $tag = isset($match[0][0]) ? (string) $match[0][0] : '';
            $offset = isset($match[0][1]) ? (int) $match[0][1] : -1;
            if ('' === $tag || $offset < 0 || !preg_match('/^<script\b[^>]*>/i', $tag, $open_match)) {
                continue;
            }

            $open = (string) $open_match[0];
            $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ('' === $src) {
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'data-ultracache-src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if ('' === $src) {
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'data-ultracache-original-src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            $id = (string) $this->extract_attribute_from_html_tag($open, 'id');
            if ('' === $id) {
                $id = (string) $this->extract_attribute_from_html_tag($open, 'data-ultracache-id');
            }

            $type = strtolower((string) $this->extract_attribute_from_html_tag($open, 'type'));
            $is_delayed = (false !== stripos($type, 'ultracache-delayed') || false !== stripos($open, 'data-ultracache-src=') || false !== stripos($open, 'data-ultracache-inline=') || false !== stripos($open, 'data-ultracache-delayed'));

            $code = (string) preg_replace('/^<script\b[^>]*>|<\/script>$/is', '', $tag);
            if ('' === $id && '' !== trim($code) && preg_match('/#\s*sourceURL\s*=\s*([^\s\r\n<]+)/i', $code, $source_url_match)) {
                $source_id = trim((string) $source_url_match[1]);
                $source_id = preg_replace('/[?#].*$/', '', $source_id);
                $source_id = basename((string) $source_id);
                if (is_string($source_id) && '' !== $source_id) {
                    $id = sanitize_text_field(substr($source_id, 0, 160));
                }
            }

            $handle = $this->infer_script_handle_from_tag($open, $src);
            if ('' === $handle && '' !== $src) {
                $handle = $src;
            }
            if ('' === $handle && '' !== $id) {
                $handle = $id;
            }

            $consent_original_id = $this->ultracache_consent_original_script_id_from_tag($open);
            $group_handle = '' !== $consent_original_id ? $consent_original_id : $handle;

            $records[$index] = array(
                'tag' => $tag,
                'open' => $open,
                'offset' => $offset,
                'src' => $src,
                'id' => $id,
                'handle' => $handle,
                'consent_original_id' => $consent_original_id,
                'group' => $this->normalize_delayed_script_group_handle($group_handle),
                'has_src' => ('' !== $src),
                'delayed' => (bool) $is_delayed,
                'inline_delayed' => (bool) (false !== stripos($open, 'data-ultracache-inline=') || false !== stripos($open, 'data-ultracache-inline="1"') || false !== stripos($open, "data-ultracache-inline='1'")),
                'code' => ('' === $src) ? $code : '',
            );
        }

        return $records;
    }



    private function strip_native_loading_attributes_from_script_tag($tag)
    {
        $tag = (string) $tag;
        if ('' === $tag) {
            return $tag;
        }

        if (false === stripos($tag, ' async') && false === stripos($tag, ' defer') && false === stripos($tag, 'data-wp-strategy=')) {
            return $tag;
        }

        $tag = $this->remove_html_tag_attribute($tag, 'async');
        $tag = $this->remove_html_tag_attribute($tag, 'defer');
        $tag = $this->remove_html_tag_attribute($tag, 'data-wp-strategy');
        $tag = preg_replace('/\s{2,}/', ' ', $tag);

        return is_string($tag) ? $tag : '';
    }



    private function normalize_protected_script_loading_attributes_in_html($html, array $settings = array())
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
            return $html;
        }

        $processed = $this->normalize_protected_script_loading_attributes_with_processor($html, $settings);
        return is_string($processed) ? $processed : $html;
    }



    private function normalize_protected_script_loading_attributes_with_processor($html, array $settings = array())
    {
        if (!$this->html_tag_processor_available()) {
            return null;
        }

        try {
            $processor = new WP_HTML_Tag_Processor((string) $html);
            $changed = false;
            $defer_all_js = !empty($settings['defer_all_js']);

            while ($processor->next_tag('SCRIPT')) {
                $async = $processor->get_attribute('async');
                $defer = $processor->get_attribute('defer');
                $strategy = $processor->get_attribute('data-wp-strategy');
                if (null === $async && null === $defer && null === $strategy) {
                    continue;
                }

                $handle = $processor->get_attribute('id');
                $src = $processor->get_attribute('src');
                $handle = (null === $handle || false === $handle) ? '' : html_entity_decode((string) $handle, ENT_QUOTES, 'UTF-8');
                $src = (null === $src || false === $src) ? '' : html_entity_decode((string) $src, ENT_QUOTES, 'UTF-8');
                $tag = $this->get_current_html_processor_tag_markup($processor, 'script');

                if ($this->should_protect_elementor_compatibility_script($handle, $src, $tag, $settings)) {
                    $processor->remove_attribute('async');
                    $processor->remove_attribute('data-wp-strategy');
                    $processor->set_attribute('defer', 'defer');
                    $changed = true;
                    continue;
                }

                if ($this->should_keep_script_blocking_for_defer_all($handle, $src, $tag, $settings)
                    || (!$defer_all_js && $this->is_script_force_blocking($handle, $src, $tag, $settings))) {
                    $processor->remove_attribute('async');
                    $processor->remove_attribute('defer');
                    $processor->remove_attribute('data-wp-strategy');
                    $changed = true;
                    continue;
                }

                if (!$defer_all_js && $this->is_script_user_force_deferred($handle, $src, $tag, $settings)) {
                    $processor->remove_attribute('async');
                    $processor->remove_attribute('data-wp-strategy');
                    $processor->set_attribute('defer', 'defer');
                    $changed = true;
                    continue;
                }
            }

            if (!$changed) {
                return null;
            }

            $updated_html = $processor->get_updated_html();
            return is_string($updated_html) && '' !== $updated_html ? $updated_html : null;
        } catch (\Throwable $e) {
            return null;
        }
    }




    /**
     * Resolve delayed inline configuration blocks that are safe to execute
     * immediately before one of their declared runtime dependencies.
     *
     * The inversion is intentionally narrow and source-proven. The inline
     * handle must publish a global and dispatch a named ready event, while the
     * registered dependency must both consume a pre-existing copy of that
     * global and listen for the same ready event. A document.readyState/load
     * fallback is also required because that is the lifecycle edge that makes
     * normal WordPress ordering unsafe after UltraCache moves the pair beyond
     * window.load.
     *
     * @param string $handle  Inline-only WordPress script handle.
     * @param string $content Inline JavaScript source.
     * @return array<int,string>
     */
    private function get_delayed_inline_pre_dependency_handles($handle, $content)
    {
        $handle = sanitize_key((string) $handle);
        $content = (string) $content;
        if ('' === $handle || '' === trim($content) || !function_exists('wp_scripts')) {
            return array();
        }

        $globals = array();
        if (preg_match_all('/\\b(?:window|globalThis)\\s*\\.\\s*([A-Za-z_$][A-Za-z0-9_$]*)\\s*=(?!=)/m', $content, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $symbol) {
                $symbol = (string) $symbol;
                if ('' !== $symbol) {
                    $globals[$symbol] = true;
                }
            }
        }
        if (empty($globals)) {
            return array();
        }

        $events = array();
        if (preg_match_all('/\\bdispatchEvent\\s*\\(\\s*new\\s+(?:CustomEvent|Event)\\s*\\(\\s*(["\\\'])([^"\\\']+)\\1/i', $content, $matches)) {
            foreach ((array) ($matches[2] ?? array()) as $event_name) {
                $event_name = trim((string) $event_name);
                if ('' !== $event_name && strlen($event_name) <= 160) {
                    $events[$event_name] = true;
                }
            }
        }
        if (empty($events)) {
            return array();
        }

        $wp_scripts = wp_scripts();
        if (!is_object($wp_scripts) || empty($wp_scripts->registered[$handle]) || !is_object($wp_scripts->registered[$handle])) {
            return array();
        }

        $proven = array();
        foreach ((array) ($wp_scripts->registered[$handle]->deps ?? array()) as $dependency) {
            $dependency = sanitize_key((string) $dependency);
            if ('' === $dependency || empty($wp_scripts->registered[$dependency]) || !is_object($wp_scripts->registered[$dependency])) {
                continue;
            }

            $registered_src = trim((string) ($wp_scripts->registered[$dependency]->src ?? ''));
            if ('' === $registered_src) {
                continue;
            }

            $public_src = $this->absolutize_public_resource_url($registered_src, home_url('/'));
            $local_path = $this->resolve_local_path_from_public_url($public_src);
            if ('' === $local_path || !is_readable($local_path)) {
                continue;
            }

            $size = @filesize($local_path);
            if (false !== $size && (int) $size > 1048576) {
                continue;
            }

            $source = ultracache_guarded_asset_file_get_contents($local_path, 'js', 'js_delayed_lifecycle_contract', true);
            if (!is_string($source) || '' === $source) {
                continue;
            }

            $has_preexisting_global_path = false;
            foreach (array_keys($globals) as $symbol) {
                $global_pattern = '(?:window|globalThis)\\s*\\.\\s*' . preg_quote($symbol, '/') . '\\b';
                if (preg_match('/\\bif\\s*\\(\\s*' . $global_pattern . '\\s*\\)/i', $source)
                    || preg_match('/' . $global_pattern . '\\s*\\?/i', $source)
                    || preg_match('/' . $global_pattern . '\\s*&&/i', $source)) {
                    $has_preexisting_global_path = true;
                    break;
                }
            }
            if (!$has_preexisting_global_path) {
                continue;
            }

            $has_ready_listener = false;
            foreach (array_keys($events) as $event_name) {
                if (preg_match('/\\baddEventListener\\s*\\(\\s*(["\\\'])' . preg_quote($event_name, '/') . '\\1/i', $source)) {
                    $has_ready_listener = true;
                    break;
                }
            }
            if (!$has_ready_listener) {
                continue;
            }

            $has_complete_fallback = preg_match('/\\bdocument\\s*\\.\\s*readyState\\b/i', $source)
                && preg_match('/(["\\\'])complete\\1/i', $source)
                && preg_match('/\\b(?:window\\s*\\.\\s*)?addEventListener\\s*\\(\\s*(["\\\'])load\\1/i', $source);
            if (!$has_complete_fallback) {
                continue;
            }

            $proven[$dependency] = true;
        }

        return array_keys($proven);
    }



    private function get_delayed_inline_pre_dependency_metadata($handle, $content)
    {
        $handles = $this->get_delayed_inline_pre_dependency_handles($handle, $content);
        return empty($handles) ? '' : implode(',', $handles);
    }



    private function build_delayed_script_tag($tag, $handle, $src, $reason = '')
    {
        $original_attributes = $this->extract_html_tag_attributes($tag);
        $preserved_attributes = array();

        foreach ($original_attributes as $name => $value) {
            $name_lc = strtolower((string) $name);
            if (in_array($name_lc, array('src', 'async', 'defer', 'data-wp-strategy'), true)) {
                continue;
            }

            if ('type' === $name_lc && !$this->is_javascript_mime_type((string) $value)) {
                continue;
            }

            if (0 === strpos($name_lc, 'data-ultracache-')) {
                continue;
            }

            $preserved_attributes[$name_lc] = (string) $value;
        }

        $delayed_src = $this->absolutize_public_resource_url($src, home_url('/'));
        if ('' === $delayed_src) {
            $delayed_src = (string) $src;
        }

        $attributes = array(
            'type'                   => 'text/ultracache-delayed-js',
            'data-ultracache-src'          => $delayed_src,
            'data-ultracache-original-src' => (string) $src,
            'data-ultracache-handle'       => (string) $handle,
        );

        if ($this->script_handle_has_active_dependency_edges($handle)) {
            $attributes['data-ultracache-ordered'] = '1';
        }

        /*
         * Keep the real WordPress dependency edges on the executable DELAY
         * placeholder. This is transport topology, not routing policy: it never
         * promotes a script out of DELAY. The browser loader uses these exact
         * edges to preserve provider -> consumer order inside the delayed lane.
         */
        $dependency_metadata = '';
        if (isset($original_attributes['data-ultracache-deps'])) {
            $dependency_metadata = preg_replace('/[^a-z0-9_,-]+/i', '', (string) $original_attributes['data-ultracache-deps']);
        }
        if ('' === (string) $dependency_metadata && function_exists('wp_scripts')) {
            $wp_scripts = wp_scripts();
            $registered_handle = sanitize_key((string) $handle);
            if (is_object($wp_scripts) && '' !== $registered_handle && !empty($wp_scripts->registered[$registered_handle]) && is_object($wp_scripts->registered[$registered_handle])) {
                $deps = array();
                foreach ((array) ($wp_scripts->registered[$registered_handle]->deps ?? array()) as $dependency) {
                    $dependency = sanitize_key((string) $dependency);
                    if ('' !== $dependency) {
                        $deps[$dependency] = true;
                    }
                }
                if (!empty($deps)) {
                    $dependency_metadata = implode(',', array_keys($deps));
                }
            }
        }
        if ('' !== (string) $dependency_metadata) {
            $attributes['data-ultracache-deps'] = (string) $dependency_metadata;
        }

        $reason = sanitize_key((string) $reason);
        if ('' !== $reason) {
            $attributes['data-ultracache-delay-reason'] = $reason;
        }

        if (!empty($preserved_attributes)) {
            $encoded = base64_encode((string) wp_json_encode($preserved_attributes));
            if ('' !== $encoded) {
                $attributes['data-ultracache-attrs'] = $encoded;
            }
        }

        foreach (array('id', 'crossorigin', 'referrerpolicy', 'integrity', 'nonce') as $attribute) {
            if (isset($preserved_attributes[$attribute]) && '' !== $preserved_attributes[$attribute]) {
                $attributes['data-ultracache-' . $attribute] = (string) $preserved_attributes[$attribute];
            }
        }

        // This rewrites an already-rendered script into an UltraCache delayed placeholder after the enqueue phase.
        // Use the WordPress script-tag API rather than compiling raw HTML attributes manually.
        return rtrim(wp_get_script_tag($attributes), "\r\n");
    }



    private function normalize_delayed_script_group_handle($handle)
    {
        $handle = strtolower(trim((string) $handle));
        if ('' === $handle) {
            return '';
        }

        $handle = preg_replace('/-js(?:-extra|-before|-after|-translations)?$/', '', $handle);
        $handle = preg_replace('/-(?:extra|before|after)$/', '', (string) $handle);
        $handle = preg_replace('/\.min\.js$|\.js$/', '', (string) $handle);

        return is_string($handle) ? trim($handle) : '';
    }



    /**
     * Respect established script-level optimizer opt-out attributes emitted by
     * plugins, CMPs, CDN loaders, and integration SDKs. Only explicit skip
     * markers are interoperability contracts here; generic CMP metadata such as
     * data-consent-category must not opt an executable payload out of optimization.
     *
     * @param string $tag Rendered script tag.
     * @return bool
     */
    private function is_script_tag_optimizer_opted_out($tag)
    {
        $tag = (string) $tag;
        if ('' === $tag || false === stripos($tag, '<script')) {
            return false;
        }

        $attributes = $this->extract_html_tag_attributes($tag);
        if (empty($attributes)) {
            return false;
        }

        if (array_key_exists('data-cfasync', $attributes)) {
            $cfasync = strtolower(trim((string) $attributes['data-cfasync']));
            if (in_array($cfasync, array('false', '0', 'off', 'no'), true)) {
                return true;
            }
        }

        $explicit_opt_outs = array(
            'data-no-defer',
            'data-noptimize',
            'data-no-optimize',
            'data-skip-moving',
            'data-skip-lazy-load',
            'nitro-exclude',
            'data-dont-merge',
            'data-wpmeteor-nooptimize',
            'data-pagespeed-no-defer',
            'consent-skip-blocker',
            'data-consent-skip-blocker',
        );

        foreach ($explicit_opt_outs as $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                return true;
            }
        }

        return false;
    }



    private function is_delayable_inline_script_tag($tag)
    {
        $tag = (string) $tag;
        if ('' === $tag || false === stripos($tag, '<script')) {
            return false;
        }

        if ($this->is_script_tag_optimizer_opted_out($tag)) {
            return false;
        }

        if (false !== stripos($tag, 'id="ultracache-runtime-js-scan-collector"') || false !== stripos($tag, "id='ultracache-runtime-js-scan-collector'") || false !== stripos($tag, '__ultracacheRuntimeJsScan')) {
            return false;
        }

        if (false !== stripos($tag, ' src=') || false !== stripos($tag, ' data-ultracache-src=') || false !== stripos($tag, 'text/ultracache-delayed-js')) {
            return false;
        }

        $type = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag, 'type')));
        if ('' !== $type && !$this->is_javascript_mime_type($type)) {
            return false;
        }

        $code = trim((string) preg_replace('/^<script\b[^>]*>|<\/script>$/is', '', $tag));
        if ('' === $code) {
            return false;
        }

        if (false !== stripos($code, '__ultracacheDelayLoader') || false !== stripos($code, 'wp-emoji-settings') || false !== stripos($code, '_wpemojiSettings')) {
            return false;
        }

        if ($this->ultracache_inline_script_uses_document_stream_write($code)) {
            return false;
        }

        return true;
    }



    private function ultracache_inline_script_uses_document_stream_write($code)
    {
        $code = (string) $code;
        if ('' === $code || false === stripos($code, 'document')) {
            return false;
        }

        if (1 === preg_match('/(?:^|[^a-z0-9_$])document\s*(?:\?\.|\.)\s*write(?:ln)?\s*\(/i', $code)) {
            return true;
        }

        return 1 === preg_match('/(?:^|[^a-z0-9_$])document\s*(?:\?\.\s*)?\[\s*(["\'])write(?:ln)?\1\s*\]\s*\(/i', $code);
    }



    private function build_delayed_inline_script_tag($tag, $handle, $reason = '')
    {
        $tag = (string) $tag;
        if ('' === $tag || !preg_match('/^<script\b[^>]*>(.*?)<\/script>$/is', $tag, $content_match)) {
            return $tag;
        }

        $content = isset($content_match[1]) ? (string) $content_match[1] : '';
        $original_attributes = $this->extract_html_tag_attributes($tag);
        $preserved_attributes = array();

        foreach ($original_attributes as $name => $value) {
            $name_lc = strtolower((string) $name);
            if (in_array($name_lc, array('src', 'async', 'defer', 'data-wp-strategy'), true)) {
                continue;
            }
            if ('type' === $name_lc && !$this->is_javascript_mime_type((string) $value)) {
                continue;
            }
            if (0 === strpos($name_lc, 'data-ultracache-')) {
                continue;
            }
            $preserved_attributes[$name_lc] = (string) $value;
        }

        $attributes = array(
            'type'             => 'text/ultracache-delayed-js',
            'data-ultracache-inline' => '1',
            'data-ultracache-handle' => (string) $handle,
        );

        $pre_dependency_metadata = $this->get_delayed_inline_pre_dependency_metadata($handle, $content);
        if ('' !== $pre_dependency_metadata) {
            $attributes['data-ultracache-before-deps'] = $pre_dependency_metadata;
        }

        $reason = sanitize_key((string) $reason);
        if ('' !== $reason) {
            $attributes['data-ultracache-delay-reason'] = $reason;
        }

        if (!empty($preserved_attributes)) {
            $encoded = base64_encode((string) wp_json_encode($preserved_attributes));
            if ('' !== $encoded) {
                $attributes['data-ultracache-attrs'] = $encoded;
            }
        }

        foreach (array('id', 'nonce') as $attribute) {
            if (isset($preserved_attributes[$attribute]) && '' !== $preserved_attributes[$attribute]) {
                $attributes['data-ultracache-' . $attribute] = (string) $preserved_attributes[$attribute];
            }
        }

        // This rewrites an already-rendered inline script into an UltraCache delayed placeholder after the enqueue phase.
        // Use the WordPress inline-script tag API so core safely serializes the attributes and script body.
        return rtrim(wp_get_inline_script_tag($content, $attributes), "\r\n");
    }



    private function extract_html_tag_attributes($tag)
    {
        $attributes = array();
        $tag = (string) $tag;
        if ('' === $tag || false === strpos($tag, '<')) {
            return $attributes;
        }

        $processed = $this->extract_html_tag_attributes_with_processor($tag);
        if (is_array($processed)) {
            return $processed;
        }

        $inside = preg_replace('/^\s*<[a-zA-Z][a-zA-Z0-9:-]*\b/i', '', $tag, 1);
        $inside = preg_replace('/>.*$/s', '', is_string($inside) ? $inside : '');
        if (!is_string($inside) || '' === trim($inside)) {
            return $attributes;
        }

        if (preg_match_all('/\s+([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/i', $inside, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (empty($match[1])) {
                    continue;
                }

                $name = strtolower((string) $match[1]);
                $value = '';
                if (isset($match[2]) && '' !== $match[2]) {
                    $value = (string) $match[2];
                } elseif (isset($match[3]) && '' !== $match[3]) {
                    $value = (string) $match[3];
                } elseif (isset($match[4]) && '' !== $match[4]) {
                    $value = (string) $match[4];
                } else {
                    $value = $name;
                }

                $attributes[$name] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
            }
        }

        return $attributes;
    }



    private function extract_html_tag_attributes_with_processor($tag)
    {
        if (!$this->html_tag_processor_available()) {
            return null;
        }

        try {
            $processor = new WP_HTML_Tag_Processor((string) $tag);
            if (!$processor->next_tag()) {
                return null;
            }

            $attributes = array();
            $tag_markup = $this->get_current_html_processor_tag_markup($processor, (string) $processor->get_tag());
            if (preg_match_all('/\s+([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/i', $tag_markup, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (empty($match[1])) {
                        continue;
                    }

                    $name = strtolower((string) $match[1]);
                    $value = $processor->get_attribute($name);
                    if (true === $value) {
                        $attributes[$name] = $name;
                    } elseif (false === $value || null === $value) {
                        $attributes[$name] = '';
                    } else {
                        $attributes[$name] = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
                    }
                }
            }

            return $attributes;
        } catch (\Throwable $e) {
            return null;
        }
    }



    private function is_delayable_external_script_tag($tag)
    {
        $tag = (string) $tag;
        if ($this->is_script_tag_optimizer_opted_out($tag)) {
            return false;
        }

        if (false !== stripos($tag, ' nomodule')) {
            return false;
        }

        $type = $this->extract_attribute_from_html_tag($tag, 'type');
        if ('' === $type) {
            return true;
        }

        return $this->is_javascript_mime_type($type);
    }



    private function is_javascript_mime_type($type)
    {
        $type = strtolower(trim((string) $type));
        if ('' === $type) {
            return true;
        }

        $type = preg_replace('/\s*;.*$/', '', $type);
        return in_array($type, array(
            'text/javascript',
            'application/javascript',
            'application/ecmascript',
            'text/ecmascript',
            'text/jscript',
            'application/x-javascript',
        ), true);
    }



    private function apply_delayed_script_replacements_with_processor($html, array $records, array $replacements)
    {
        if (!$this->html_tag_processor_available() || !is_string($html) || '' === $html || empty($records) || empty($replacements)) {
            return null;
        }

        try {
            $processor = new WP_HTML_Tag_Processor((string) $html);
            $index = 0;
            $changed = false;

            while ($processor->next_tag('SCRIPT')) {
                if (!isset($records[$index]) || !isset($replacements[$index])) {
                    $index++;
                    continue;
                }

                $replacement = (string) $replacements[$index];
                $replacement_attributes = $this->extract_html_tag_attributes($replacement);
                if (empty($replacement_attributes)) {
                    $index++;
                    continue;
                }

                $record = $records[$index];
                $open = (string) $record['open'];
                $original_attributes = $this->extract_html_tag_attributes($open);
                foreach (array_keys($original_attributes) as $attribute) {
                    $attribute = strtolower(trim((string) $attribute));
                    if ('' !== $attribute) {
                        $processor->remove_attribute($attribute);
                    }
                }

                foreach (array('src', 'async', 'defer', 'data-wp-strategy', 'type', 'id', 'crossorigin', 'referrerpolicy', 'integrity', 'nonce') as $attribute) {
                    $processor->remove_attribute($attribute);
                }

                foreach ($replacement_attributes as $attribute => $value) {
                    $attribute = strtolower(trim((string) $attribute));
                    if ('' === $attribute) {
                        continue;
                    }

                    $processor->set_attribute($attribute, (string) $value);
                }

                $changed = true;
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



    private function delay_third_party_analytics_scripts_in_html($html, array $settings = array())
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '<script') || (empty($settings['delay_safe_third_party_js']) && empty($settings['delay_functional_third_party_js']) && empty($settings['delay_all_third_party_js']))) {
            return $html;
        }

        if (!preg_match_all('/<script\b[^>]*>.*?<\/script>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $records = array();
        foreach ($matches as $index => $match) {
            $tag = isset($match[0][0]) ? (string) $match[0][0] : '';
            $offset = isset($match[0][1]) ? (int) $match[0][1] : -1;
            if ('' === $tag || $offset < 0 || !preg_match('/^<script\b[^>]*>/i', $tag, $open_match)) {
                continue;
            }

            $open = (string) $open_match[0];
            $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ('' === $src) {
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'data-ultracache-src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if ('' === $src) {
                $src = html_entity_decode((string) $this->extract_attribute_from_html_tag($open, 'data-ultracache-original-src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            $id = (string) $this->extract_attribute_from_html_tag($open, 'id');
            if ('' === $id) {
                $id = (string) $this->extract_attribute_from_html_tag($open, 'data-ultracache-id');
            }
            $code = (string) preg_replace('/^<script\b[^>]*>|<\/script>$/is', '', $tag);
            if ('' === $id && '' !== trim($code) && preg_match('/#\s*sourceURL\s*=\s*([^\s\r\n<]+)/i', $code, $source_url_match)) {
                $source_id = trim((string) $source_url_match[1]);
                $source_id = preg_replace('/[?#].*$/', '', $source_id);
                $source_id = basename((string) $source_id);
                if (is_string($source_id) && '' !== $source_id) {
                    $id = sanitize_text_field(substr($source_id, 0, 160));
                }
            }

            $type = strtolower((string) $this->extract_attribute_from_html_tag($open, 'type'));
            $is_delayed = (false !== stripos($type, 'ultracache-delayed') || false !== stripos($open, 'data-ultracache-src=') || false !== stripos($open, 'data-ultracache-inline=') || false !== stripos($open, 'data-ultracache-delayed'));
            $handle = $this->infer_script_handle_from_tag($open, $src);
            if ('' === $handle && '' !== $src) {
                $handle = $src;
            }
            if ('' === $handle && '' !== $id) {
                $handle = $id;
            }

            $records[$index] = array(
                'tag'     => $tag,
                'open'    => $open,
                'offset'  => $offset,
                'src'     => $src,
                'id'      => $id,
                'handle'  => $handle,
                'group'   => $this->normalize_delayed_script_group_handle($handle),
                'has_src' => ('' !== $src),
                'delayed' => (bool) $is_delayed,
                'inline_delayed' => (bool) (false !== stripos($open, 'data-ultracache-inline=') || false !== stripos($open, 'data-ultracache-inline="1"') || false !== stripos($open, "data-ultracache-inline='1'")),
                'code'    => ('' === $src) ? $code : '',
            );
        }

        if (empty($records)) {
            return $html;
        }

        $protected_groups = $this->get_user_excluded_script_dependency_groups($records, $settings);
        $protected_indexes = $this->get_user_excluded_script_dependency_indexes($records, $settings);
        $force_defer_groups = $this->get_user_force_deferred_script_dependency_groups($records, $settings);
        $optimizer_opt_out_groups = array();
        foreach ($records as $native_record) {
            $native_group = isset($native_record['group']) ? (string) $native_record['group'] : '';
            if ('' === $native_group) {
                continue;
            }
            $native_handle = isset($native_record['handle']) ? (string) $native_record['handle'] : '';
            $native_src = isset($native_record['src']) ? (string) $native_record['src'] : '';
            $native_tag = isset($native_record['tag']) ? (string) $native_record['tag'] : '';
            if ($this->is_script_tag_optimizer_opted_out($native_tag)) {
                $optimizer_opt_out_groups[$native_group] = true;
            }
        }
        $replacements = array();
        foreach ($records as $index => $record) {
            if (empty($record['has_src']) || '' === $record['src']) {
                continue;
            }

            $record_group = isset($record['group']) ? (string) $record['group'] : '';
            $optimizer_opted_out = '' !== $record_group && !empty($optimizer_opt_out_groups[$record_group]);
            if (isset($protected_indexes[(int) $index])
                || $this->script_record_matches_user_defer_exclusion($record, $settings)
                || ('' !== $record_group && !empty($protected_groups[$record_group]))
                || $optimizer_opted_out) {
                continue;
            }

            if ($this->script_record_matches_user_force_defer($record, $settings) || ('' !== $record_group && !empty($force_defer_groups[$record_group]))) {
                $deferred = $this->add_defer_or_parallel_attribute_to_script_tag($record['tag'], $record['src'], $settings, true);
                if (is_string($deferred) && '' !== $deferred && $deferred !== $record['tag']) {
                    $replacements[$index] = $deferred;
                }
                continue;
            }

            $match = $this->get_third_party_delay_match($record['handle'], $record['src'], $record['open'], $settings);
            if (empty($match['matched'])) {
                continue;
            }

            $reason = isset($match['reason']) ? (string) $match['reason'] : 'third-party';
            $replacements[$index] = $this->build_delayed_script_tag($record['open'], $record['handle'], $record['src'], $reason);

            if ('' === $record['group']) {
                continue;
            }

            foreach ($records as $inline_index => $inline_record) {
                if ($inline_index === $index || !empty($inline_record['has_src']) || isset($replacements[$inline_index])) {
                    continue;
                }
                $inline_group = isset($inline_record['group']) ? (string) $inline_record['group'] : '';
                if (isset($protected_indexes[(int) $inline_index])
                    || ('' !== $inline_group && (!empty($protected_groups[$inline_group]) || !empty($optimizer_opt_out_groups[$inline_group])))) {
                    continue;
                }
                if ('' !== $inline_group && !empty($force_defer_groups[$inline_group]) && $this->is_delayable_inline_script_tag($inline_record['tag'])) {
                    $externalized = $this->build_deferred_external_inline_script_tag($inline_record, $settings);
                    if (is_string($externalized) && '' !== $externalized && $externalized !== $inline_record['tag']) {
                        $replacements[$inline_index] = $externalized;
                    }
                    continue;
                }
                if ('' === $inline_record['group'] || $inline_record['group'] !== $record['group']) {
                    continue;
                }
                if (!$this->is_delayable_inline_script_tag($inline_record['tag'])) {
                    continue;
                }
                $replacements[$inline_index] = $this->build_delayed_inline_script_tag($inline_record['tag'], $inline_record['handle'], $reason);
            }
        }

        foreach ($records as $inline_index => $inline_record) {
            if (!empty($inline_record['has_src']) || isset($replacements[$inline_index])) {
                continue;
            }
            $inline_group = isset($inline_record['group']) ? (string) $inline_record['group'] : '';
            if (isset($protected_indexes[(int) $inline_index])
                || $this->script_record_matches_user_defer_exclusion($inline_record, $settings)
                || ('' !== $inline_group && (!empty($protected_groups[$inline_group]) || !empty($optimizer_opt_out_groups[$inline_group])))) {
                continue;
            }
            if (($this->script_record_matches_user_force_defer($inline_record, $settings) || ('' !== $inline_group && !empty($force_defer_groups[$inline_group]))) && $this->is_delayable_inline_script_tag($inline_record['tag'])) {
                $externalized = $this->build_deferred_external_inline_script_tag($inline_record, $settings);
                if (is_string($externalized) && '' !== $externalized && $externalized !== $inline_record['tag']) {
                    $replacements[$inline_index] = $externalized;
                }
                continue;
            }
            if (!$this->is_delayable_inline_script_tag($inline_record['tag'])) {
                continue;
            }

            if ($this->is_wp_inline_dependency_script_record($inline_record)) {
                continue;
            }

            $inline_match = $this->get_inline_third_party_delay_match($inline_record['handle'], $inline_record['tag'], $settings);
            if (empty($inline_match['matched'])) {
                continue;
            }
            $inline_reason = isset($inline_match['reason']) ? (string) $inline_match['reason'] : 'third-party';
            $replacements[$inline_index] = $this->build_delayed_inline_script_tag($inline_record['tag'], $inline_record['handle'], $inline_reason);
        }

        if (empty($replacements)) {
            return $html;
        }

        ksort($replacements);
        $processed = $this->apply_delayed_script_replacements_with_processor($html, $records, $replacements);

        return is_string($processed) ? $processed : $html;
    }



    private function ultracache_apply_script_record_replacements_by_offset($html, array $records, array $replacements)
    {
        if (!is_string($html) || '' === $html || empty($replacements)) {
            return $html;
        }

        ksort($replacements);
        $out = '';
        $last = 0;
        foreach ($replacements as $index => $replacement) {
            if (!isset($records[$index])) {
                continue;
            }
            $offset = isset($records[$index]['offset']) ? (int) $records[$index]['offset'] : -1;
            $tag = isset($records[$index]['tag']) ? (string) $records[$index]['tag'] : '';
            if ($offset < $last || $offset < 0 || '' === $tag) {
                continue;
            }
            $out .= substr($html, $last, $offset - $last) . (string) $replacement;
            $last = $offset + strlen($tag);
        }

        return $out . substr($html, $last);
    }



    /**
     * Return the effective execution lane of one rendered script record.
     *
     * NATIVE is parser/native execution, DEFER includes defer/async transport,
     * and DELAY is an UltraCache inert delayed placeholder.
     */
    private function ultracache_script_record_lane(array $record)
    {
        if (!empty($record['delayed'])) {
            return 'delay';
        }

        $tag = isset($record['tag']) ? (string) $record['tag'] : '';
        if (preg_match('/\s(?:defer|async)(?:\s|=|>)/i', $tag)
            || preg_match('/\sdata-wp-strategy\s*=\s*(["\'])(?:defer|async)\1/i', $tag)) {
            return 'defer';
        }

        return 'native';
    }



    private function ultracache_script_lane_rank($lane)
    {
        $lane = strtolower(trim((string) $lane));
        if ('native' === $lane) {
            return 0;
        }
        if ('defer' === $lane) {
            return 1;
        }
        return 2;
    }



    /**
     * Return the executable script mode used by final identity dedupe.
     *
     * The delayed loader already treats classic/module and nomodule variants as
     * distinct execution identities. The final HTML pass mirrors that contract
     * after LCP/dependency lane promotion so restored placeholders do not execute
     * a second copy of a payload the loader would previously have discarded.
     */
    private function ultracache_script_record_execution_mode(array $record)
    {
        $open = (string) ($record['open'] ?? '');
        $attrs = $this->extract_html_tag_attributes($open);
        if (!empty($record['delayed'])) {
            $preserved = $this->decode_delayed_script_preserved_attributes($attrs);
            foreach ($preserved as $name => $value) {
                if (!array_key_exists($name, $attrs)) {
                    $attrs[$name] = $value;
                }
            }
        }

        $type = strtolower(trim((string) ($attrs['type'] ?? '')));
        if ('text/ultracache-delayed-js' === $type) {
            $type = '';
        }
        $nomodule = array_key_exists('nomodule', $attrs);

        return $type . '|nomodule:' . ($nomodule ? '1' : '0');
    }



    /** Return true when a rendered record represents executable JavaScript. */
    private function ultracache_script_record_is_executable_js(array $record)
    {
        if (!empty($record['delayed'])) {
            return true;
        }

        $open = (string) ($record['open'] ?? '');
        $type = strtolower(trim((string) $this->extract_attribute_from_html_tag($open, 'type')));
        if ('' === $type || 'module' === $type) {
            return true;
        }

        return $this->is_javascript_mime_type($type);
    }



    /**
     * Build the generic execution-identity keys for one final script record.
     *
     * Contract mirrors delayed-js-loader.js:
     * - duplicate id means one executable instance;
     * - external scripts dedupe by absolute normalized src + JS mode;
     * - inline scripts dedupe only when they have a handle/id and identical code.
     */
    private function ultracache_script_record_execution_identity_keys(array $record)
    {
        if (!$this->ultracache_script_record_is_executable_js($record)) {
            return array();
        }

        $keys = array();
        $id = trim((string) ($record['id'] ?? ''));
        $mode = $this->ultracache_script_record_execution_mode($record);
        if ('' !== $id) {
            $keys[] = 'id:' . $id;
        }

        if (!empty($record['has_src'])) {
            $src = (string) ($record['src'] ?? '');
            $absolute = $this->absolutize_public_resource_url($src, home_url('/'));
            if ('' === $absolute) {
                $absolute = $src;
            }
            $normalized = $this->normalize_public_resource_url($absolute);
            if ('' !== $normalized) {
                $keys[] = 'src:' . $normalized . '|mode:' . $mode;
            }
        } else {
            $handle = trim((string) ($record['handle'] ?? ''));
            $code = (string) ($record['code'] ?? '');
            if ('' !== $handle && '' !== $code) {
                $keys[] = 'inline:' . $handle . ':' . hash('sha256', $code) . '|mode:' . $mode;
            } elseif ('' !== $id && '' !== $code) {
                $keys[] = 'inline-id:' . $id . ':' . hash('sha256', $code) . '|mode:' . $mode;
            }
        }

        return array_values(array_unique($keys));
    }



    /**
     * Select one executable survivor for every connected duplicate identity set.
     * Earlier lanes win (NATIVE, then DEFER, then DELAY); DOM order breaks ties.
     *
     * @return array<int,bool> Indexes that must be removed from final HTML.
     */
    private function ultracache_select_final_duplicate_script_indexes(array $records)
    {
        $parents = array();
        $owners = array();

        $find = static function ($index) use (&$parents, &$find) {
            if (!isset($parents[$index])) {
                $parents[$index] = $index;
            }
            if ($parents[$index] !== $index) {
                $parents[$index] = $find($parents[$index]);
            }
            return $parents[$index];
        };
        $union = static function ($a, $b) use (&$parents, &$find) {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parents[$rb] = $ra;
            }
        };

        $eligible = array();
        foreach ($records as $index => $record) {
            $keys = $this->ultracache_script_record_execution_identity_keys($record);
            if (empty($keys)) {
                continue;
            }
            $index = (int) $index;
            $eligible[$index] = true;
            $parents[$index] = $index;
            foreach ($keys as $key) {
                if (isset($owners[$key])) {
                    $union($index, (int) $owners[$key]);
                } else {
                    $owners[$key] = $index;
                }
            }
        }

        if (count($eligible) < 2) {
            return array();
        }

        $groups = array();
        foreach (array_keys($eligible) as $index) {
            $root = $find($index);
            if (!isset($groups[$root])) {
                $groups[$root] = array();
            }
            $groups[$root][] = $index;
        }

        $remove = array();
        foreach ($groups as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            $survivor = null;
            $survivor_rank = 99;
            $survivor_offset = PHP_INT_MAX;
            foreach ($indexes as $index) {
                if (!isset($records[$index])) {
                    continue;
                }
                $rank = $this->ultracache_script_lane_rank($this->ultracache_script_record_lane($records[$index]));
                $offset = isset($records[$index]['offset']) ? (int) $records[$index]['offset'] : PHP_INT_MAX;
                if (null === $survivor || $rank < $survivor_rank || ($rank === $survivor_rank && $offset < $survivor_offset)) {
                    $survivor = $index;
                    $survivor_rank = $rank;
                    $survivor_offset = $offset;
                }
            }
            if (null === $survivor) {
                continue;
            }
            foreach ($indexes as $index) {
                if ((int) $index !== (int) $survivor) {
                    $remove[(int) $index] = true;
                }
            }
        }

        return $remove;
    }



    /**
     * Build the one shared DEFER dispatcher tag for an inline registry entry.
     *
     * The dispatcher asset is shared by every DEFER occurrence on the page, so
     * the browser fetches one tiny file while each script occurrence keeps its
     * exact DOM position relative to external deferred scripts.
     */
    private function ultracache_build_inline_registry_defer_dispatcher_tag(array $record, $key, $fingerprint)
    {
        if (!defined('ULTRACACHE_URL')) {
            return (string) ($record['tag'] ?? '');
        }

        $source_tag = (string) ($record['tag'] ?? '');
        $original_attributes = $this->extract_html_tag_attributes($source_tag);
        $preserved_attributes = array();
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
            $preserved_attributes[$name_lc] = is_scalar($value) ? (string) $value : '';
        }

        $encoded_attrs = base64_encode((string) wp_json_encode($preserved_attributes));
        $dispatcher_src = trailingslashit((string) ULTRACACHE_URL) . 'assets/js/inline-registry-dispatcher.js';
        if (defined('ULTRACACHE_VERSION')) {
            $dispatcher_src = add_query_arg('ver', rawurlencode((string) ULTRACACHE_VERSION), $dispatcher_src);
        }

        $attrs = array(
            'src' => $dispatcher_src,
            'defer' => true,
            'data-ultracache-inline-registry-dispatcher' => '1',
            'data-ultracache-inline-registry-key' => (string) $key,
            'data-ultracache-inline-fingerprint' => (string) $fingerprint,
            'data-ultracache-inline-registry-attrs' => $encoded_attrs,
            'data-ultracache-finder-bypass' => '1',
            'data-ultracache-lane' => 'defer',
        );

        $handle = trim((string) ($record['handle'] ?? ''));
        if ('' !== $handle) {
            $attrs['data-ultracache-handle'] = $handle;
        }
        foreach (array('id', 'nonce') as $attribute) {
            if (isset($preserved_attributes[$attribute]) && '' !== (string) $preserved_attributes[$attribute]) {
                $attrs[$attribute] = (string) $preserved_attributes[$attribute];
            }
        }

        return rtrim(wp_get_script_tag($attrs), "\r\n");
    }



    /** Replace one delayed inline body with a registry-key placeholder. */
    private function ultracache_build_inline_registry_delay_placeholder(array $record, $key, $fingerprint)
    {
        $tag = (string) ($record['tag'] ?? '');
        if ('' === $tag) {
            return $tag;
        }

        $attrs = $this->extract_html_tag_attributes($tag);
        if (empty($attrs)) {
            return $tag;
        }
        $attrs['data-ultracache-inline-registry-key'] = (string) $key;
        $attrs['data-ultracache-inline-fingerprint'] = (string) $fingerprint;
        $attrs['data-ultracache-inline-registry'] = '1';

        return rtrim(wp_get_inline_script_tag('', $attrs), "\r\n");
    }



    /**
     * Collect every non-NATIVE inline JavaScript occurrence into one inert page
     * registry while preserving occurrence identity and execution topology.
     *
     * DEFER entries become a shared external dispatcher at the original DOM
     * position. DELAY entries remain delayed placeholders but carry only a
     * registry key. NATIVE and parser-sensitive/explicit-opt-out scripts remain
     * browser-owned.
     */
    private function ultracache_collect_non_native_inline_registry_in_html($html, array $settings = array())
    {
        unset($settings);

        if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
            return $html;
        }

        $records = $this->collect_script_dependency_records_from_html($html);
        if (empty($records)) {
            return $html;
        }

        $entries = array();
        $replacements = array();
        $ordinal = 0;

        foreach ($records as $index => $record) {
            if (!empty($record['has_src']) || !$this->ultracache_script_record_is_executable_js($record)) {
                continue;
            }

            $tag = (string) ($record['tag'] ?? '');
            if ('' === $tag
                || false !== stripos($tag, 'data-ultracache-inline-registry-manifest=')
                || false !== stripos($tag, 'data-ultracache-inline-registry-dispatcher=')) {
                continue;
            }

            $lane = $this->ultracache_script_record_lane($record);
            if (!in_array($lane, array('defer', 'delay'), true)) {
                continue;
            }

            $source_tag = !empty($record['delayed']) ? $this->restore_delayed_script_record_tag($record) : $tag;
            if (!$this->is_delayable_inline_script_tag($source_tag)) {
                continue;
            }

            $code = (string) preg_replace('/^<script\\b[^>]*>|<\\/script>$/is', '', $source_tag);
            if ('' === trim($code)) {
                continue;
            }

            $ordinal++;
            $fingerprint = hash('sha256', $code);
            $key = 'uc-inline-' . $ordinal . '-' . substr($fingerprint, 0, 16);
            $handle = trim((string) ($record['handle'] ?? ''));
            $id = trim((string) ($record['id'] ?? ''));

            $entries[$key] = array(
                'ordinal' => $ordinal,
                'lane' => $lane,
                'handle' => $handle,
                'id' => $id,
                'fingerprint' => $fingerprint,
                'code' => $code,
            );

            if ('defer' === $lane) {
                $replacement = $this->ultracache_build_inline_registry_defer_dispatcher_tag($record, $key, $fingerprint);
            } else {
                $replacement = $this->ultracache_build_inline_registry_delay_placeholder($record, $key, $fingerprint);
            }

            if (is_string($replacement) && '' !== $replacement && $replacement !== $tag) {
                $replacements[(int) $index] = $replacement;
            } else {
                unset($entries[$key]);
                $ordinal--;
            }
        }

        if (empty($entries) || empty($replacements)) {
            return $html;
        }

        $html = $this->ultracache_apply_script_record_replacements_by_offset($html, $records, $replacements);
        if (!is_string($html) || '' === $html) {
            return $html;
        }

        $manifest = array(
            'version' => 1,
            'count' => count($entries),
            'entries' => $entries,
        );
        $json = wp_json_encode($manifest, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || '' === $json) {
            return $html;
        }

        $manifest_tag = rtrim(wp_get_inline_script_tag($json, array(
            'type' => 'application/json',
            'id' => 'ultracache-inline-registry-v1',
            'data-ultracache-inline-registry-manifest' => '1',
            'data-ultracache-inline-registry-count' => (string) count($entries),
        )), "\r\n");

        $body_pos = strripos($html, '</body>');
        if (false !== $body_pos) {
            return substr($html, 0, $body_pos) . $manifest_tag . "\n" . substr($html, $body_pos);
        }

        $html_pos = strripos($html, '</html>');
        if (false !== $html_pos) {
            return substr($html, 0, $html_pos) . $manifest_tag . "\n" . substr($html, $html_pos);
        }

        return $html . "\n" . $manifest_tag;
    }



    /**
     * Enforce one final executable instance per generic script identity after all
     * LCP and dependency lane promotions have completed.
     */
    private function ultracache_dedupe_final_script_execution_identities_in_html($html)
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
            return $html;
        }

        $records = $this->collect_script_dependency_records_from_html($html);
        if (empty($records)) {
            return $html;
        }

        $remove = $this->ultracache_select_final_duplicate_script_indexes($records);
        if (empty($remove)) {
            return $html;
        }

        $replacements = array();
        foreach (array_keys($remove) as $index) {
            $replacements[(int) $index] = '';
        }

        return $this->ultracache_apply_script_record_replacements_by_offset($html, $records, $replacements);
    }



    /**
     * Rebuild one rendered script record in an earlier/equal execution lane.
     * Dependency normalization never pushes a script later than its selected
     * policy lane; it only preserves an executable ordering invariant.
     */
    private function ultracache_build_script_record_for_lane(array $record, $lane, array $settings = array(), $reason = 'dependency-lane-coherence')
    {
        $lane = strtolower(trim((string) $lane));
        $source_tag = !empty($record['delayed']) ? $this->restore_delayed_script_record_tag($record) : (string) ($record['tag'] ?? '');
        if ('' === $source_tag) {
            return '';
        }

        $handle = isset($record['handle']) ? (string) $record['handle'] : '';
        $src = isset($record['src']) ? (string) $record['src'] : '';

        if ('native' === $lane) {
            return !empty($record['has_src']) ? $this->strip_native_loading_attributes_from_script_tag($source_tag) : $source_tag;
        }

        if ('defer' === $lane) {
            if (!empty($record['has_src'])) {
                return $this->add_defer_attribute_to_script_tag($source_tag, true);
            }
            if (!$this->is_delayable_inline_script_tag($source_tag)) {
                return $source_tag;
            }
            $defer_record = $record;
            $defer_record['tag'] = $source_tag;
            $defer_record['delayed'] = false;
            return $this->build_deferred_external_inline_script_tag($defer_record, $settings, true);
        }

        if ('delay' === $lane) {
            if (!empty($record['delayed'])) {
                return (string) ($record['tag'] ?? '');
            }
            if (!empty($record['has_src'])) {
                return $this->build_delayed_script_tag($source_tag, $handle, $src, $reason);
            }
            if ($this->is_delayable_inline_script_tag($source_tag)) {
                return $this->build_delayed_inline_script_tag($source_tag, $handle, $reason);
            }
        }

        return $source_tag;
    }



    /** Return the concrete execution phase of one WordPress script-family record. */
    private function ultracache_script_family_record_phase(array $record)
    {
        if (!empty($record['has_src'])) {
            return 'external';
        }

        $phase_id = trim((string) ($record['consent_original_id'] ?? ''));
        $id = strtolower('' !== $phase_id ? $phase_id : trim((string) ($record['id'] ?? '')));
        if (preg_match('/-js-before$/', $id)) {
            return 'before';
        }
        if (preg_match('/-js(?:-extra)?$/', $id) || preg_match('/-js-extra$/', $id)) {
            return 'data';
        }
        if (preg_match('/-js-translations$/', $id)) {
            return 'translations';
        }
        if (preg_match('/-js-after$/', $id)) {
            return 'after';
        }
        return 'inline';
    }



    /**
     * Return the structural Real Cookie Banner consent contract carried by an
     * inline WordPress family companion. This is intentionally vendor-agnostic:
     * the application/consent + consent-* attributes are the browser contract.
     */
    private function ultracache_application_consent_contract_from_record(array $record)
    {
        $open = (string) ($record['open'] ?? '');
        if ('' === $open) {
            return array();
        }

        $type = strtolower(trim((string) $this->extract_attribute_from_html_tag($open, 'type')));
        if ('application/consent' !== $type) {
            return array();
        }

        $required = trim((string) $this->extract_attribute_from_html_tag($open, 'consent-required'));
        $by = trim((string) $this->extract_attribute_from_html_tag($open, 'consent-by'));
        $consent_id = trim((string) $this->extract_attribute_from_html_tag($open, 'consent-id'));
        if ('' === $required || '' === $by || '' === $consent_id) {
            return array();
        }

        $original_type = trim((string) $this->extract_attribute_from_html_tag($open, 'consent-original-type-_'));
        if ('' === $original_type) {
            $original_type = 'application/javascript';
        }

        return array(
            'consent-required' => $required,
            'consent-by' => $by,
            'consent-id' => $consent_id,
            'consent-original-type-_' => $original_type,
        );
    }



    /**
     * Rebuild an external consumer under the same application/consent contract
     * as its inline WordPress family companion. Real Cookie Banner represents
     * blocked external scripts with consent-original-src-_ and no live src; the
     * CMP then restores the payload after consent in original DOM order.
     */
    private function ultracache_build_application_consent_external_script_tag(array $record, array $contract)
    {
        $source_tag = !empty($record['delayed']) ? $this->restore_delayed_script_record_tag($record) : (string) ($record['tag'] ?? '');
        if ('' === $source_tag) {
            return '';
        }

        $src = trim((string) ($record['src'] ?? ''));
        if ('' === $src) {
            $src = trim((string) $this->extract_attribute_from_html_tag($source_tag, 'src'));
        }
        if ('' === $src) {
            $src = trim((string) $this->extract_attribute_from_html_tag($source_tag, 'consent-original-src-_'));
        }
        if ('' === $src) {
            return $source_tag;
        }

        $source_attrs = $this->extract_html_tag_attributes($source_tag);
        $attributes = array();
        foreach (array('id', 'nonce', 'crossorigin', 'referrerpolicy', 'integrity', 'nomodule') as $name) {
            if (!array_key_exists($name, $source_attrs)) {
                continue;
            }
            $value = $source_attrs[$name];
            $attributes[$name] = true === $value || $value === $name ? true : (string) $value;
        }

        $attributes['consent-original-src-_'] = $src;
        foreach (array('consent-required', 'consent-by', 'consent-id', 'consent-original-type-_') as $name) {
            if (isset($contract[$name]) && '' !== trim((string) $contract[$name])) {
                $attributes[$name] = (string) $contract[$name];
            }
        }
        $attributes['type'] = 'application/consent';

        return rtrim(wp_get_script_tag($attributes), "\r\n");
    }



    /**
     * Keep WordPress inline-before/after/data/translations companions in the
     * same lane as their registered external script without forcing the whole
     * group NATIVE. The selected lane remains policy-owned; family metadata only
     * preserves the original WordPress execution topology inside that lane.
     */
    private function ultracache_normalize_inline_companion_group_lanes_in_html($html, array $settings = array())
    {
        $records = $this->collect_script_dependency_records_from_html($html);
        if (empty($records)) {
            return $html;
        }

        $groups = array();
        foreach ($records as $index => $record) {
            $group = isset($record['group']) ? (string) $record['group'] : '';
            if ('' === $group) {
                continue;
            }
            if (!isset($groups[$group])) {
                $groups[$group] = array();
            }
            $groups[$group][] = (int) $index;
        }

        $replacements = array();
        foreach ($groups as $group => $indexes) {
            $external = array();
            $inline = array();
            foreach ($indexes as $index) {
                if (!isset($records[$index])) {
                    continue;
                }
                if (!empty($records[$index]['has_src'])) {
                    $external[] = $index;
                } else {
                    $inline[] = $index;
                }
            }
            if (empty($external) || empty($inline)) {
                continue;
            }

            /*
             * If an inline companion is already owned by an application/consent
             * controller, the external consumer must inherit that exact consent
             * contract instead of being released as native JavaScript. Otherwise
             * the consumer can execute before its consent-held data/before script.
             */
            $consent_contract = array();
            $consent_contract_conflict = false;
            foreach ($inline as $index) {
                if (!isset($records[$index])) {
                    continue;
                }
                $candidate = $this->ultracache_application_consent_contract_from_record($records[$index]);
                if (empty($candidate)) {
                    continue;
                }
                if (empty($consent_contract)) {
                    $consent_contract = $candidate;
                    continue;
                }
                foreach (array('consent-required', 'consent-by', 'consent-id') as $name) {
                    if ((string) ($consent_contract[$name] ?? '') !== (string) ($candidate[$name] ?? '')) {
                        $consent_contract_conflict = true;
                        break 2;
                    }
                }
            }

            if (!empty($consent_contract) && !$consent_contract_conflict) {
                $family_sequence = 0;
                foreach ($indexes as $index) {
                    if (!isset($records[$index])) {
                        continue;
                    }
                    $family_sequence++;
                    if (!empty($records[$index]['has_src'])) {
                        $replacement = $this->ultracache_build_application_consent_external_script_tag($records[$index], $consent_contract);
                    } else {
                        $replacement = (string) ($records[$index]['tag'] ?? '');
                    }
                    if (!is_string($replacement) || '' === $replacement) {
                        continue;
                    }
                    $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ultracache-family', sanitize_key((string) $group));
                    $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ultracache-family-sequence', (string) $family_sequence);
                    $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ultracache-family-phase', $this->ultracache_script_family_record_phase($records[$index]));
                    if ($replacement !== (string) ($records[$index]['tag'] ?? '')) {
                        $replacements[(int) $index] = $replacement;
                    }
                }
                continue;
            }

            $target_rank = 2;
            foreach ($external as $index) {
                $target_rank = min($target_rank, $this->ultracache_script_lane_rank($this->ultracache_script_record_lane($records[$index])));
            }

            foreach ($inline as $index) {
                $source_tag = !empty($records[$index]['delayed']) ? $this->restore_delayed_script_record_tag($records[$index]) : (string) ($records[$index]['tag'] ?? '');
                if ($this->is_script_tag_optimizer_opted_out($source_tag) || !$this->is_delayable_inline_script_tag($source_tag)) {
                    $target_rank = 0;
                    break;
                }
            }

            $target_lane = 0 === $target_rank ? 'native' : (1 === $target_rank ? 'defer' : 'delay');
            $group_replacements = array();
            $defer_failed = false;
            $family_sequence = 0;
            foreach ($indexes as $index) {
                if (!isset($records[$index])) {
                    continue;
                }
                $family_sequence++;
                $replacement = $this->ultracache_build_script_record_for_lane($records[$index], $target_lane, $settings, 'inline-companion-lane-coherence');
                if ('defer' === $target_lane && empty($records[$index]['has_src']) && $replacement === (string) ($records[$index]['tag'] ?? '')) {
                    $defer_failed = true;
                    break;
                }
                if (!is_string($replacement) || '' === $replacement) {
                    continue;
                }

                $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ultracache-family', sanitize_key((string) $group));
                $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ultracache-family-sequence', (string) $family_sequence);
                $replacement = $this->set_or_add_html_tag_attribute($replacement, 'data-ultracache-family-phase', $this->ultracache_script_family_record_phase($records[$index]));

                if ($replacement !== (string) ($records[$index]['tag'] ?? '')) {
                    $group_replacements[$index] = $replacement;
                }
            }

            if ($defer_failed) {
                $group_replacements = array();
                foreach ($indexes as $index) {
                    if (!isset($records[$index])) {
                        continue;
                    }
                    $replacement = $this->ultracache_build_script_record_for_lane($records[$index], 'native', $settings, 'inline-companion-native-fallback');
                    if (is_string($replacement) && '' !== $replacement && $replacement !== (string) ($records[$index]['tag'] ?? '')) {
                        $group_replacements[$index] = $replacement;
                    }
                }
            }

            foreach ($group_replacements as $index => $replacement) {
                $replacements[(int) $index] = $replacement;
            }
        }

        if (empty($replacements)) {
            return $html;
        }

        return $this->ultracache_apply_script_record_replacements_by_offset($html, $records, $replacements);
    }



    private function ultracache_resolve_dependency_lane_ranks(array $handle_rank, array $dependency_map)
    {
        $target_rank = array();
        foreach ($handle_rank as $handle => $rank) {
            $handle = sanitize_key((string) $handle);
            if ('' === $handle) {
                continue;
            }
            $target_rank[$handle] = max(0, min(2, (int) $rank));
        }

        $changed = true;
        $iterations = 0;
        $max_iterations = max(1, count($target_rank) + 1);
        while ($changed && $iterations < $max_iterations) {
            $changed = false;
            $iterations++;
            foreach ($target_rank as $dependent => $dependent_rank) {
                foreach ((array) ($dependency_map[$dependent] ?? array()) as $dependency) {
                    $dependency = sanitize_key((string) $dependency);
                    if ('' === $dependency || !isset($target_rank[$dependency])) {
                        continue;
                    }
                    if ($target_rank[$dependency] > $dependent_rank) {
                        $target_rank[$dependency] = $dependent_rank;
                        $changed = true;
                    }
                }
            }
        }

        return $target_rank;
    }



    /**
     * Enforce the generic dependency invariant on the final rendered lanes:
     * a registered dependency must never execute later than its dependent.
     *
     * This replaces the old blanket "has a dependent => cannot DELAY" rule.
     * A dependency may remain DELAY when every dependent is also DELAY. If a
     * dependent is DEFER/NATIVE, only the required upstream dependency chain is
     * promoted to the same earlier lane. No vendor identity participates.
     */
    private function ultracache_normalize_registered_dependency_lanes_in_html($html, array $settings = array())
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '<script')) {
            return $html;
        }

        $html = $this->ultracache_normalize_inline_companion_group_lanes_in_html($html, $settings);
        $records = $this->collect_script_dependency_records_from_html($html);
        if (empty($records)) {
            return $html;
        }

        global $wp_scripts;
        if (!($wp_scripts instanceof WP_Scripts) || empty($wp_scripts->registered) || !is_array($wp_scripts->registered)) {
            return $html;
        }

        $handle_indexes = array();
        $handle_rank = array();
        foreach ($records as $index => $record) {
            $handle = sanitize_key((string) ($record['handle'] ?? ''));
            if ('' === $handle || empty($wp_scripts->registered[$handle]) || !is_object($wp_scripts->registered[$handle])) {
                continue;
            }
            if (!isset($handle_indexes[$handle])) {
                $handle_indexes[$handle] = array();
                $handle_rank[$handle] = 2;
            }
            $handle_indexes[$handle][] = (int) $index;
            $handle_rank[$handle] = min($handle_rank[$handle], $this->ultracache_script_lane_rank($this->ultracache_script_record_lane($record)));
        }

        if (empty($handle_indexes)) {
            return $html;
        }

        $dependency_map = array();
        foreach (array_keys($handle_rank) as $dependent) {
            if (empty($wp_scripts->registered[$dependent]) || !is_object($wp_scripts->registered[$dependent])) {
                continue;
            }
            $dependency_map[$dependent] = array_values(array_filter(array_map('sanitize_key', array_map('strval', (array) ($wp_scripts->registered[$dependent]->deps ?? array())))));
        }
        $target_rank = $this->ultracache_resolve_dependency_lane_ranks($handle_rank, $dependency_map);

        $replacements = array();
        foreach ($target_rank as $handle => $rank) {
            if (!isset($handle_rank[$handle]) || $rank >= $handle_rank[$handle]) {
                continue;
            }
            $target_lane = 0 === $rank ? 'native' : 'defer';
            foreach ($handle_indexes[$handle] as $index) {
                if (!isset($records[$index])) {
                    continue;
                }
                $group = (string) ($records[$index]['group'] ?? '');
                foreach ($records as $candidate_index => $candidate_record) {
                    if ('' !== $group && (string) ($candidate_record['group'] ?? '') !== $group) {
                        continue;
                    }
                    if ('' === $group && (int) $candidate_index !== (int) $index) {
                        continue;
                    }
                    $replacement = $this->ultracache_build_script_record_for_lane($candidate_record, $target_lane, $settings, 'registered-dependency-lane-coherence');
                    if (is_string($replacement) && '' !== $replacement && $replacement !== (string) ($candidate_record['tag'] ?? '')) {
                        $replacements[(int) $candidate_index] = $replacement;
                    }
                }
            }
        }

        if (!empty($replacements)) {
            $html = $this->ultracache_apply_script_record_replacements_by_offset($html, $records, $replacements);
        }

        return $this->ultracache_normalize_inline_companion_group_lanes_in_html($html, $settings);
    }



    private function get_inline_script_code_from_tag($tag)
    {
        $tag = (string) $tag;
        if ('' === $tag) {
            return '';
        }

        $start = stripos($tag, '>');
        if (false === $start) {
            return '';
        }

        $end = strripos($tag, '</script>');
        if (false === $end || $end <= $start) {
            return '';
        }

        return substr($tag, $start + 1, $end - $start - 1);
    }



    private function infer_script_handle_from_tag($tag, $src = '')
    {
        $handle = $this->extract_attribute_from_html_tag($tag, 'data-ultracache-handle');
        $handle = trim((string) $handle);
        if ('' !== $handle) {
            return $handle;
        }

        $id = $this->extract_attribute_from_html_tag($tag, 'id');
        $id = trim((string) $id);
        if ('' === $id) {
            $id = trim((string) $this->extract_attribute_from_html_tag($tag, 'data-ultracache-id'));
        }
        if ('' !== $id) {
            $id = preg_replace('/-js(?:-extra|-before|-after|-translations)?$/', '', $id);
            return is_string($id) ? $id : '';
        }

        $path = (string) wp_parse_url((string) $src, PHP_URL_PATH);
        $base = basename($path);
        if ('' === $base) {
            return '';
        }

        return preg_replace('/\.min\.js$|\.js$/i', '', $base);
    }

}
