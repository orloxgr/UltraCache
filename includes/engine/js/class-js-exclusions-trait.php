<?php
/**
 * JavaScript exclusion, force-defer, and matching helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_JS_Exclusions_Trait
{

    private function get_user_excluded_script_dependency_groups(array $records, array $settings = array())
    {
        $protected = array();

        foreach ($records as $record) {
            if (!$this->script_record_matches_user_defer_exclusion($record, $settings)) {
                continue;
            }

            $group = isset($record['group']) ? (string) $record['group'] : '';
            if ('' !== $group) {
                $protected[$group] = true;
            }
        }

        return $protected;
    }



    private function get_user_force_deferred_script_dependency_groups(array $records, array $settings = array())
    {
        $protected = array();
        $native_groups = $this->get_user_excluded_script_dependency_groups($records, $settings);

        foreach ($records as $record) {
            if (!$this->script_record_matches_user_force_defer($record, $settings)) {
                continue;
            }

            $group = isset($record['group']) ? (string) $record['group'] : '';
            if ('' !== $group && empty($native_groups[$group])) {
                $protected[$group] = true;
            }
        }

        return $protected;
    }



    private function get_user_excluded_script_dependency_indexes(array $records, array $settings = array())
    {
        $protected = array();
        $protected_groups = $this->get_user_excluded_script_dependency_groups($records, $settings);
        $ordered_indexes = array_keys($records);
        sort($ordered_indexes);
        $count = count($ordered_indexes);

        foreach ($ordered_indexes as $position => $index) {
            if (!isset($records[$index])) {
                continue;
            }

            $record = $records[$index];
            $group = isset($record['group']) ? (string) $record['group'] : '';

            if ($this->is_ultracache_frontend_js_helper_record($record)) {
                $protected[(int) $index] = true;
                if ('' !== $group) {
                    $protected_groups[$group] = true;
                }
                continue;
            }

            $matches_user_exclusion = $this->script_record_matches_user_defer_exclusion($record, $settings);
            $matches_protected_group = ('' !== $group && !empty($protected_groups[$group]));

            if (!$matches_user_exclusion && !$matches_protected_group) {
                continue;
            }

            $protected[(int) $index] = true;

            if (empty($record['has_src'])) {
                continue;
            }

            /*
             * A visible exclusion for an external provider must also keep
             * its immediately attached inline consumer/config blocks in the
             * same parser-executed sequence. This is inheritance from the
             * user's explicit exclusion line, not a hidden default rule.
             */
            for ($next_position = $position + 1; $next_position < $count; $next_position++) {
                $next_index = $ordered_indexes[$next_position];
                if (!isset($records[$next_index])) {
                    continue;
                }

                $next_record = $records[$next_index];
                if (!empty($next_record['has_src'])) {
                    break;
                }

                if (!$this->is_delayable_inline_script_tag(isset($next_record['tag']) ? (string) $next_record['tag'] : '')) {
                    continue;
                }

                $protected[(int) $next_index] = true;

                $next_group = isset($next_record['group']) ? (string) $next_record['group'] : '';
                if ('' !== $next_group) {
                    $protected_groups[$next_group] = true;
                }
            }
        }

        foreach ($records as $index => $record) {
            $group = isset($record['group']) ? (string) $record['group'] : '';
            if ('' !== $group && !empty($protected_groups[$group])) {
                $protected[(int) $index] = true;
            }
        }

        return $protected;
    }



    private function add_script_record_dependency_protection(array &$protected, array $records, array $ordered_indexes, $index, $radius = 3)
    {
        if (!isset($records[$index])) {
            return;
        }

        $group = isset($records[$index]['group']) ? (string) $records[$index]['group'] : '';
        if ('' !== $group) {
            $protected[$group] = true;
        }

        $nearby_groups = $this->get_nearby_script_dependency_groups($records, $ordered_indexes, (int) $index, (int) $radius);
        foreach ($nearby_groups as $nearby_group) {
            if ('' !== $nearby_group) {
                $protected[$nearby_group] = true;
            }
        }
    }



    private function add_user_exclusion_defining_script_protection(array &$protected, array $records, array $ordered_indexes, array $matched_indexes, array $settings = array())
    {
        $fragments = $this->get_defer_stage_user_exclude_fragments($settings);
        $definition_fragments = $this->get_definition_candidate_fragments_from_user_exclusions($fragments);
        if (empty($definition_fragments)) {
            return;
        }

        foreach ($records as $index => $record) {
            if (isset($matched_indexes[(int) $index]) || empty($record['has_src'])) {
                continue;
            }

            if (!$this->script_record_matches_definition_candidate_fragments($record, $definition_fragments)) {
                continue;
            }

            $this->add_script_record_dependency_protection($protected, $records, $ordered_indexes, (int) $index, 4);
        }
    }



    private function get_definition_candidate_fragments_from_user_exclusions(array $fragments)
    {
        $candidates = array();
        foreach ($fragments as $fragment) {
            $fragment = trim((string) $fragment);
            if ('' === $fragment) {
                continue;
            }

            $lc = strtolower($fragment);
            $normalized = $this->normalize_js_fragment_match_text($lc);
            if (strlen($normalized) < 6) {
                continue;
            }

            $tokens = preg_split('/[^a-z0-9]+/i', $fragment, -1, PREG_SPLIT_NO_EMPTY);
            if (empty($tokens) || 1 === count($tokens)) {
                $camel = preg_replace('/(?<!^)([A-Z])/', ' $1', $fragment);
                $tokens = preg_split('/[^a-z0-9]+/i', (string) $camel, -1, PREG_SPLIT_NO_EMPTY);
            }

            $clean_tokens = array();
            foreach ((array) $tokens as $token) {
                $token = strtolower(trim((string) $token));
                if (strlen($token) >= 3 && !in_array($token, array('params', 'data', 'min', 'script', 'function'), true)) {
                    $clean_tokens[] = $token;
                }
            }

            $candidates[$lc] = $lc;
            $candidates[$normalized] = $normalized;

            if (count($clean_tokens) >= 2) {
                $dash = implode('-', $clean_tokens);
                $plain = implode('', $clean_tokens);
                $candidates[$dash] = $dash;
                $candidates[$plain] = $plain;
            }

        }

        return array_values(array_unique(array_filter($candidates, static function ($candidate) {
            return is_string($candidate) && strlen($candidate) >= 6;
        })));
    }



    private function script_record_matches_definition_candidate_fragments(array $record, array $fragments)
    {
        $haystacks = array(
            isset($record['handle']) ? (string) $record['handle'] : '',
            isset($record['src']) ? (string) $record['src'] : '',
            (string) wp_parse_url(isset($record['src']) ? (string) $record['src'] : '', PHP_URL_PATH),
            isset($record['id']) ? (string) $record['id'] : '',
            isset($record['open']) ? (string) $record['open'] : '',
        );

        $normalized_haystacks = array();
        foreach ($haystacks as $haystack) {
            $haystack = strtolower(trim((string) $haystack));
            if ('' === $haystack) {
                continue;
            }
            $normalized_haystacks[] = $haystack;
            $normalized_haystacks[] = $this->normalize_js_fragment_match_text($haystack);
        }

        foreach ($fragments as $fragment) {
            $fragment = strtolower(trim((string) $fragment));
            if ('' === $fragment) {
                continue;
            }
            $normalized_fragment = $this->normalize_js_fragment_match_text($fragment);
            foreach ($normalized_haystacks as $haystack) {
                if ('' !== $haystack && (false !== strpos($haystack, $fragment) || (strlen($normalized_fragment) >= 6 && false !== strpos($haystack, $normalized_fragment)))) {
                    return true;
                }
            }
        }

        return false;
    }



    private function matched_exclusions_need_jquery_legacy_cluster(array $records, array $matched_indexes, array $settings = array())
    {
        $fragments = strtolower(implode(' ', array_map('strval', $this->get_defer_stage_user_exclude_fragments($settings))));
        $legacy_markers = array(
            'jquery',
            'jquery-migrate',
            'functions.js',
            'themes/',
        );
        if (function_exists('ultracache_themes_public_paths')) {
            foreach (ultracache_themes_public_paths() as $theme_marker) {
                $legacy_markers[] = $theme_marker;
            }
        }
        foreach ($legacy_markers as $marker) {
            if (false !== strpos($fragments, $marker)) {
                return true;
            }
        }

        foreach ($matched_indexes as $index => $_) {
            if (empty($records[$index])) {
                continue;
            }
            $record = $records[$index];
            $haystack = strtolower(
                (isset($record['handle']) ? (string) $record['handle'] : '') . ' ' .
                (isset($record['src']) ? (string) $record['src'] : '') . ' ' .
                (isset($record['tag']) ? (string) $record['tag'] : '') . ' ' .
                (isset($record['code']) ? (string) $record['code'] : '')
            );
            if (false !== strpos($haystack, 'jquery')) {
                return true;
            }
            if (function_exists('ultracache_public_path_contains_any') && ultracache_public_path_contains_any($haystack, ultracache_themes_public_paths())) {
                return true;
            }
        }

        return false;
    }




private function script_record_matches_user_defer_exclusion(array $record, array $settings = array())
    {
        $tag = isset($record['tag']) ? (string) $record['tag'] : (isset($record['open']) ? (string) $record['open'] : '');
        $code = isset($record['code']) ? (string) $record['code'] : '';

        return $this->is_js_excluded_by_user_patterns(
            isset($record['handle']) ? (string) $record['handle'] : '',
            isset($record['src']) ? (string) $record['src'] : '',
            $tag,
            $code,
            $settings
        );
    }



    private function script_record_matches_user_force_defer(array $record, array $settings = array())
    {
        $tag = isset($record['tag']) ? (string) $record['tag'] : (isset($record['open']) ? (string) $record['open'] : '');

        return $this->is_script_user_force_deferred(
            isset($record['handle']) ? (string) $record['handle'] : '',
            isset($record['src']) ? (string) $record['src'] : '',
            $tag,
            $settings
        );
    }



    private function is_script_optimization_excluded($handle, $src, $tag = '', array $settings = array())
    {
        return $this->is_script_force_blocking($handle, $src, $tag, $settings)
            || $this->is_script_user_defer_excluded($handle, $src, $settings, $tag)
            || $this->is_script_safe_stage_excluded($handle, $src, $tag, $settings);
    }



    private function is_script_absolute_defer_blocking($handle, $src, $tag = '', array $settings = array())
    {
        /*
         * Absolute dependency recommendations are no longer forced by a
         * hidden runtime list. Populate Defaults places those entries in
         * the visible Do Not Defer or Delay fallback textarea.
         */
        return false;
    }



    private function is_script_force_blocking($handle, $src, $tag = '', array $settings = array())
    {
        /*
         * Generic engine force-blocking contains no vendor/plugin policy.
         * Compatibility belongs to visible lists or explicit integration
         * switches handled before this fallback.
         */
        return false;
    }



    private function is_script_user_force_deferred($handle, $src, $tag = '', array $settings = array())
    {
        return $this->script_matches_force_defer_fragment_list($handle, $src, $tag, $this->get_force_defer_js_fragments($settings));
    }



    private function get_force_defer_js_fragments(array $settings = array())
    {
        $list = array();
        if (isset($settings['defer_js_force_list']) && is_array($settings['defer_js_force_list'])) {
            $list = array_merge($list, $settings['defer_js_force_list']);
        }

        return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
            return '' !== trim((string) $item);
        })));
    }



    private function script_matches_force_defer_fragment_list($handle, $src, $tag, array $fragments)
    {
        $haystacks = array(
            strtolower(trim((string) $handle)),
            strtolower(trim((string) $src)),
            strtolower((string) wp_parse_url((string) $src, PHP_URL_PATH)),
            strtolower((string) $tag),
        );

        foreach ($fragments as $fragment) {
            $fragment = strtolower(trim((string) $fragment));
            if ('' === $fragment) {
                continue;
            }
            foreach ($haystacks as $haystack) {
                if ('' !== $haystack && false !== strpos($haystack, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }



    private function is_script_user_defer_excluded($handle, $src, array $settings = array(), $tag = '', $inline_code = '')
    {
        return $this->is_js_excluded_by_user_patterns($handle, $src, $tag, $inline_code, $settings);
    }



    private function is_script_safe_stage_excluded($handle, $src, $tag = '', array $settings = array())
    {
        /*
         * No hidden safe-stage exclusions. The existing visible JS Delay /
         * Defer Exclusions textarea is the only exclusion source; Populate
         * Defaults exposes recommended dependency fragments for users to
         * add, edit, save, or remove.
         */
        return $this->is_script_user_defer_excluded($handle, $src, $settings, $tag);
    }



    private function get_defer_stage_user_exclude_fragments(array $settings = array())
    {
        $list = array();

        if (isset($settings['defer_js_exclude_list']) && is_array($settings['defer_js_exclude_list'])) {
            $list = array_merge($list, $settings['defer_js_exclude_list']);
        }

        // Backward compatibility for sites that already saved the old separate Delay Non-Critical JS exclude list.
        if (isset($settings['delay_non_critical_js_exclude_list']) && is_array($settings['delay_non_critical_js_exclude_list'])) {
            $list = array_merge($list, $settings['delay_non_critical_js_exclude_list']);
        }

        $list = array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
            return '' !== trim((string) $item);
        })));

        // The visible Do Not Defer or Delay list is the user's final
        // compatibility fallback for aggressive JS modes. Never strip
        // legacy-looking fragments here; if the user adds validation-
        // messages.js, sr7, elementor, or any other broad line, it must
        // remain effective.
        return $list;
    }



    private function get_unified_js_user_exclude_fragments(array $settings = array())
    {
        return $this->get_defer_stage_user_exclude_fragments($settings);
    }



    private function get_script_handle_group_variants($handle, $id = '')
    {
        $variants = array();

        foreach (array($handle, $id) as $value) {
            $value = strtolower(trim((string) $value));
            if ('' === $value) {
                continue;
            }

            $variants[$value] = $value;

            foreach ($this->get_js_handle_suffix_variants($value) as $variant) {
                if ('' !== $variant) {
                    $variants[$variant] = $variant;
                }
            }

            $group = $this->normalize_delayed_script_group_handle($value);
            if ('' !== $group) {
                $variants[$group] = $group;
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }



    private function get_js_handle_suffix_variants($value)
    {
        $value = strtolower(trim((string) $value));
        if ('' === $value) {
            return array();
        }

        $variants = array();
        $suffixes = array('-js-translations', '-js-before', '-js-after', '-js-extra', '-translations', '-before', '-after', '-extra', '-js');
        foreach ($suffixes as $suffix) {
            if ($this->string_ends_with_fragment($value, $suffix)) {
                $base = substr($value, 0, -strlen($suffix));
                if ('' !== $base) {
                    $variants[$base] = $base;
                }
            }
        }

        foreach (array('.min.js', '.js') as $suffix) {
            if ($this->string_ends_with_fragment($value, $suffix)) {
                $base = substr($value, 0, -strlen($suffix));
                if ('' !== $base) {
                    $variants[$base] = $base;
                }
            }
        }

        return array_values($variants);
    }



    private function string_ends_with_fragment($value, $suffix)
    {
        $value = (string) $value;
        $suffix = (string) $suffix;
        if ('' === $suffix || strlen($suffix) > strlen($value)) {
            return false;
        }

        return substr($value, -strlen($suffix)) === $suffix;
    }



    private function build_js_exclusion_match_haystacks($handle, $src, $tag = '', $inline_code = '')
    {
        $id = '';
        if ('' !== (string) $tag) {
            $id = (string) $this->extract_attribute_from_html_tag($tag, 'id');
            if ('' === $id) {
                $id = (string) $this->extract_attribute_from_html_tag($tag, 'data-ultracache-id');
            }
        }

        $haystacks = array();
        foreach ($this->get_script_handle_group_variants($handle, $id) as $variant) {
            $haystacks[] = $variant;
        }

        $src_lc = strtolower(trim((string) $src));
        if ('' !== $src_lc) {
            $haystacks[] = $src_lc;
            $path = strtolower((string) wp_parse_url($src_lc, PHP_URL_PATH));
            if ('' !== $path) {
                $haystacks[] = $path;
                $base = basename($path);
                if ('' !== $base) {
                    $haystacks[] = $base;
                    foreach ($this->get_js_handle_suffix_variants($base) as $variant) {
                        $haystacks[] = $variant;
                    }
                }
            }
        }

        if ('' !== (string) $tag) {
            $haystacks[] = strtolower((string) $tag);
        }

        if ('' !== (string) $inline_code) {
            $haystacks[] = strtolower((string) $inline_code);
        }

        return array_values(array_unique(array_filter($haystacks)));
    }



    private function is_js_directly_excluded_by_user_patterns($handle, $src, $tag = '', $inline_code = '', array $settings = array())
    {
        $fragments = $this->get_unified_js_user_exclude_fragments($settings);
        if (empty($fragments)) {
            return false;
        }

        return $this->script_matches_fragment_list_from_haystacks(
            $this->build_js_exclusion_match_haystacks($handle, $src, $tag, $inline_code),
            $fragments
        );
    }



    private function get_user_excluded_registered_script_dependency_handles(array $settings = array())
    {
        global $wp_scripts;

        if (!is_object($wp_scripts) || !isset($wp_scripts->registered) || !is_array($wp_scripts->registered) || empty($wp_scripts->registered)) {
            return array();
        }

        $fragments = $this->get_unified_js_user_exclude_fragments($settings);
        if (empty($fragments)) {
            return array();
        }

        static $cache = array();

        $registry_identity = function_exists('spl_object_hash') ? spl_object_hash($wp_scripts) : get_class($wp_scripts);
        $cache_key = hash(
            'sha256',
            $registry_identity . '|' . count($wp_scripts->registered) . '|' . implode("\n", array_map('strval', $fragments))
        );
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $protected = array();
        $pending = array();

        foreach ($wp_scripts->registered as $registered_handle => $dependency) {
            $registered_handle = trim((string) $registered_handle);
            if ('' === $registered_handle || !is_object($dependency)) {
                continue;
            }

            $registered_src = isset($dependency->src) ? (string) $dependency->src : '';
            $synthetic_tag = '<script id="' . str_replace('"', '', $registered_handle) . '-js"></script>';
            if (!$this->is_js_directly_excluded_by_user_patterns($registered_handle, $registered_src, $synthetic_tag, '', $settings)) {
                continue;
            }

            $key = strtolower($registered_handle);
            $protected[$key] = true;
            $pending[] = $registered_handle;
        }

        while (!empty($pending)) {
            $current = array_pop($pending);
            if (!isset($wp_scripts->registered[$current]) || !is_object($wp_scripts->registered[$current])) {
                continue;
            }

            $dependency = $wp_scripts->registered[$current];
            $deps = isset($dependency->deps) && is_array($dependency->deps) ? $dependency->deps : array();
            foreach ($deps as $dep_handle) {
                $dep_handle = trim((string) $dep_handle);
                if ('' === $dep_handle) {
                    continue;
                }

                $key = strtolower($dep_handle);
                if (isset($protected[$key])) {
                    continue;
                }

                $protected[$key] = true;
                if (isset($wp_scripts->registered[$dep_handle]) && is_object($wp_scripts->registered[$dep_handle])) {
                    $pending[] = $dep_handle;
                }
            }
        }

        $cache[$cache_key] = $protected;

        return $protected;
    }



    private function is_js_protected_by_user_exclusion_dependency_closure($handle, array $settings = array())
    {
        $handle = strtolower(trim((string) $handle));
        if ('' === $handle) {
            return false;
        }

        $protected = $this->get_user_excluded_registered_script_dependency_handles($settings);

        return isset($protected[$handle]);
    }



    private function is_js_excluded_by_user_patterns($handle, $src, $tag = '', $inline_code = '', array $settings = array())
    {
        if ($this->is_js_directly_excluded_by_user_patterns($handle, $src, $tag, $inline_code, $settings)) {
            return true;
        }

        /*
         * A visible Do Not Defer or Delay exclusion must not create an
         * impossible WordPress execution order. Once an explicitly excluded
         * registered handle is identified, inherit that blocking protection
         * through its real registered dependency closure for this request.
         * No provider names or plugin paths are hardcoded here.
         */
        return $this->is_js_protected_by_user_exclusion_dependency_closure($handle, $settings);
    }




private function get_safe_stage_defer_exclude_fragments(array $settings = array())
    {
        /*
         * Former built-in defer fragments are now surfaced through the
         * Do Not Defer or Delay Populate Defaults payload.
         */
        return $this->get_defer_stage_user_exclude_fragments($settings);
    }



    private function get_defer_js_exclude_fragments(array $settings = array())
    {
        return $this->get_safe_stage_defer_exclude_fragments($settings);
    }




private function get_force_blocking_script_handles(array $settings = array())
    {
        /*
         * No hidden force-blocking handles. Recommended dependency handles
         * are exposed through Do Not Defer or Delay Populate Defaults.
         */
        return array();
    }



    private function get_safe_stage_excluded_handles(array $settings = array())
    {
        /*
         * No hidden safe-stage handle list. Use the visible Do Not Defer
         * or Delay fallback textarea instead.
         */
        return array();
    }



    private function get_defer_excluded_handles(array $settings = array())
    {
        /*
         * Kept as an API surface for diagnostics. Runtime exclusions are
         * user-visible fragments only, not hidden handle lists.
         */
        return array();
    }



    private function get_delay_non_critical_js_exclude_fragments()
    {
        $settings = $this->get_settings();
        $list = $this->get_defer_stage_user_exclude_fragments(is_array($settings) ? $settings : array());

        return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
            return '' !== trim((string) $item);
        })));
    }



    private function script_matches_fragment_list($handle, $src, array $fragments)
    {
        return $this->script_matches_fragment_list_from_haystacks(
            $this->build_js_exclusion_match_haystacks($handle, $src),
            $fragments
        );
    }



    private function script_matches_fragment_list_from_haystacks(array $haystacks, array $fragments)
    {
        $haystacks = array_values(array_unique(array_filter(array_map(static function ($value) {
            return strtolower(trim((string) $value));
        }, $haystacks))));

        if (empty($haystacks) || empty($fragments)) {
            return false;
        }

        $normalized_haystacks = array();
        foreach ($haystacks as $haystack) {
            if ('' !== $haystack) {
                $normalized_haystacks[] = $this->normalize_js_fragment_match_text($haystack);
            }
        }

        foreach ($fragments as $fragment) {
            $fragment = strtolower(trim((string) $fragment));
            if ('' === $fragment) {
                continue;
            }

            if ($this->is_generic_root_js_exclusion_fragment($fragment)) {
                if ($this->generic_root_js_exclusion_matches_haystacks($fragment, $haystacks)) {
                    return true;
                }
                continue;
            }

            foreach ($haystacks as $haystack) {
                if ('' !== $haystack && false !== strpos($haystack, $fragment)) {
                    return true;
                }
            }

            $normalized_fragment = $this->normalize_js_fragment_match_text($fragment);
            if (strlen($normalized_fragment) < 4) {
                continue;
            }
            foreach ($normalized_haystacks as $normalized_haystack) {
                if ('' !== $normalized_haystack && false !== strpos($normalized_haystack, $normalized_fragment)) {
                    return true;
                }
            }
        }

        return false;
    }



    private function is_generic_root_js_exclusion_fragment($fragment)
    {
        $fragment = strtolower(trim((string) $fragment));
        if ('' === $fragment) {
            return false;
        }

        return in_array($fragment, array(
            'wordpress',
            'frontend',
            'main',
            'plugin',
            'plugins',
            'script',
            'scripts',
            'data',
            'params',
            'cart',
            'checkout',
            'account',
        ), true);
    }



    private function generic_root_js_exclusion_matches_haystacks($fragment, array $haystacks)
    {
        $fragment = strtolower(trim((string) $fragment));
        if ('' === $fragment || empty($haystacks)) {
            return false;
        }

        foreach ($haystacks as $haystack) {
            $haystack = strtolower(trim((string) $haystack));
            if ('' === $haystack) {
                continue;
            }

            if ($haystack === $fragment) {
                return true;
            }

        }

        return false;
    }



    private function normalize_js_fragment_match_text($value)
    {
        $value = strtolower((string) $value);
        $value = preg_replace('/[^a-z0-9_]+/', '', $value);

        return is_string($value) ? $value : '';
    }



    private function get_third_party_delay_exclude_fragments(array $settings = array())
    {
        $list = $this->get_defer_stage_user_exclude_fragments($settings);

        return array_values(array_unique(array_filter(array_map('strval', $list), static function ($item) {
            return '' !== trim((string) $item);
        })));
    }



    private function is_third_party_delay_excluded($handle, $src, array $settings = array(), $tag = '')
    {
        return $this->is_js_excluded_by_user_patterns($handle, $src, $tag, '', $settings);
    }



    private function is_third_party_delay_dependency_library($handle, $src, $tag = '')
    {
        unset($handle, $src, $tag);
        /*
         * Hidden third-party dependency-library bypasses are intentionally
         * disabled. If a dependency script breaks when delayed, diagnostics
         * should resolve it into the visible Defer Instead or Do Not Defer
         * or Delay boxes.
         */
        return false;
    }

}
