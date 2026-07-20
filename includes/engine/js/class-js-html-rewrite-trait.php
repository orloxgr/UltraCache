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
        $replacements = array();

        foreach ($records as $index => $record) {
            if (empty($record['delayed']) || empty($record['tag'])) {
                continue;
            }

            $group = isset($record['group']) ? (string) $record['group'] : '';
            $force_defer = $this->script_record_matches_user_force_defer($record, $settings) || ('' !== $group && !empty($force_defer_groups[$group]));
            if (!$force_defer && !isset($protected_indexes[(int) $index]) && !$this->script_record_matches_user_defer_exclusion($record, $settings) && ('' === $group || empty($protected_groups[$group]))) {
                continue;
            }

            $restored = $this->restore_delayed_script_record_tag($record);
            if ($force_defer && !empty($record['has_src'])) {
                $restored = $this->add_defer_or_parallel_attribute_to_script_tag($restored, isset($record['src']) ? (string) $record['src'] : '', $settings, true);
            } elseif ($force_defer && $this->is_delayable_inline_script_tag($restored)) {
                $externalized = $this->build_deferred_external_inline_script_tag($record, $settings);
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

        unset($preserved['type'], $preserved['async'], $preserved['defer'], $preserved['data-wp-strategy']);
        foreach (array_keys($preserved) as $name) {
            if (0 === strpos(strtolower((string) $name), 'data-ultracache-')) {
                unset($preserved[$name]);
            }
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

            $records[$index] = array(
                'tag' => $tag,
                'open' => $open,
                'offset' => $offset,
                'src' => $src,
                'id' => $id,
                'handle' => $handle,
                'group' => $this->normalize_delayed_script_group_handle($handle),
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



    private function is_delayable_inline_script_tag($tag)
    {
        $tag = (string) $tag;
        if ('' === $tag || false === stripos($tag, '<script')) {
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

        return true;
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
        $replacements = array();
        foreach ($records as $index => $record) {
            if (empty($record['has_src']) || '' === $record['src']) {
                continue;
            }

            $record_group = isset($record['group']) ? (string) $record['group'] : '';
            if (isset($protected_indexes[(int) $index]) || $this->script_record_matches_user_defer_exclusion($record, $settings) || ('' !== $record_group && !empty($protected_groups[$record_group]))) {
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
                if (isset($protected_indexes[(int) $inline_index]) || ('' !== $inline_group && !empty($protected_groups[$inline_group]))) {
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
            if (isset($protected_indexes[(int) $inline_index]) || $this->script_record_matches_user_defer_exclusion($inline_record, $settings) || ('' !== $inline_group && !empty($protected_groups[$inline_group]))) {
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
