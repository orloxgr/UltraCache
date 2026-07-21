<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Runtime_JS_Rules_Trait
{
    private function runtime_js_scan_normalize_safeguard_lists(array $safeguards)
    {
        if (isset($safeguards['fallback']) || isset($safeguards['force'])) {
            return array(
                'fallback' => isset($safeguards['fallback']) && is_array($safeguards['fallback']) ? $safeguards['fallback'] : array(),
                'force'    => isset($safeguards['force']) && is_array($safeguards['force']) ? $safeguards['force'] : array(),
            );
        }

        return array(
            'fallback' => $safeguards,
            'force'    => array(),
        );
    }

    private function runtime_js_scan_exclusion_already_matches($suggestion, array $exclusions)
    {
        $suggestion = strtolower(trim((string) $suggestion));
        if ('' === $suggestion) {
            return false;
        }
        foreach ($exclusions as $line) {
            $line = strtolower(trim((string) $line));
            if ('' === $line) {
                continue;
            }
            if ($this->runtime_js_scan_is_generic_root_exclusion_line($line)) {
                if ($this->runtime_js_scan_generic_root_exclusion_covers_suggestion($line, $suggestion)) {
                    return true;
                }
                continue;
            }
            if ($line === $suggestion || false !== strpos($suggestion, $line)) {
                return true;
            }
            if (strlen($line) >= 4 && strlen($suggestion) >= 4 && false !== strpos($line, $suggestion)) {
                return true;
            }
        }
        return false;
    }

    private function runtime_js_scan_is_generic_root_exclusion_line($line)
    {
        $line = strtolower(trim((string) $line));
        if ('' === $line) {
            return false;
        }

        return in_array($line, array(
            'woocommerce',
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

    private function runtime_js_scan_generic_root_exclusion_covers_suggestion($line, $suggestion)
    {
        $line = strtolower(trim((string) $line));
        $suggestion = strtolower(trim((string) $suggestion));
        if ('' === $line || '' === $suggestion) {
            return false;
        }

        if ($suggestion === $line) {
            return true;
        }

        if ('woocommerce' === $line) {
            return (function_exists('ultracache_public_path_contains') && ultracache_public_path_contains($suggestion, ultracache_plugins_public_path('woocommerce')))
                || false !== strpos($suggestion, '/woocommerce/assets/');
        }

        return false;
    }

    private function runtime_js_scan_is_ultracache_runtime_helper_source($source)
    {
        $source = strtolower($this->runtime_js_scan_clean_console_candidate((string) $source));
        if ('' === $source) {
            return false;
        }

        foreach (array(
            'delayed-js-loader.js',
            'runtime-js-scan-collector.js',
            'runtime-font-css-map.js',
            'font-display-cssom-patch.js',
            'mailerlite-lazy-nonce.js',
            'lcp-observer.js',
            'ultracache-delayed-js-loader',
            'ultracache-runtime-js-scan-collector',
        ) as $marker) {
            if (false !== strpos($source, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function runtime_js_scan_add_suggestion(&$suggestions, &$seen, $suggested_exclusion, $symbol, $source, $message, $reason, array $exclusions, $confidence = 'high', $preferred_target = '')
    {
        $suggested_exclusion = $this->runtime_js_scan_clean_console_candidate($suggested_exclusion);
        if ('' === $suggested_exclusion) {
            return;
        }
        if ($this->runtime_js_scan_is_ultracache_runtime_helper_source($suggested_exclusion) || $this->runtime_js_scan_is_ultracache_runtime_helper_source($source)) {
            return;
        }
        if ($this->runtime_js_scan_is_generic_token($suggested_exclusion)) {
            return;
        }
        if (preg_match('/\.js$/i', $suggested_exclusion) && $this->runtime_js_scan_is_generic_script_basename(basename($suggested_exclusion))) {
            $suggested_lc = strtolower($suggested_exclusion);
            $has_path_context = false !== strpos($suggested_lc, '/');
            $symbol_lc = strtolower(trim((string) $symbol));
            $is_confirmed_provider_path = $this->runtime_js_scan_is_explicit_missing_global_provider_path($suggested_lc, (string) $symbol)
                || ('jquery-migrate' === $symbol_lc && false !== strpos($suggested_lc, 'jquery-migrate'));
            $owner = function_exists('ultracache_plugin_theme_owner_from_public_source') ? ultracache_plugin_theme_owner_from_public_source('/' . ltrim($suggested_lc, '/')) : array();
            $is_targeted_local_asset = !empty($owner['slug'])
                || (function_exists('ultracache_public_path_contains') && function_exists('ultracache_plugins_public_path') && ultracache_public_path_contains($suggested_lc, ultracache_plugins_public_path()))
                || (function_exists('ultracache_public_path_contains_any') && function_exists('ultracache_themes_public_paths') && ultracache_public_path_contains_any($suggested_lc, ultracache_themes_public_paths()));
            if (!$has_path_context || (!$is_confirmed_provider_path && !$is_targeted_local_asset)) {
                return;
            }
        }
        $confidence = strtolower(trim((string) $confidence));
        if ('' === $confidence) {
            $confidence = 'recommended';
        }
        $preferred_target = strtolower(trim((string) $preferred_target));
        if (!in_array($preferred_target, array('force', 'exclusion'), true)) {
            $preferred_target = '';
        }
        $ignored = 'ignored' === $confidence;
        $not_fixable = 'not-fixable' === $confidence;
        $safeguards = $this->runtime_js_scan_normalize_safeguard_lists($exclusions);
        $already_excluded = $this->runtime_js_scan_exclusion_already_matches($suggested_exclusion, $safeguards['fallback']);
        $already_force_deferred = !$already_excluded && $this->runtime_js_scan_exclusion_already_matches($suggested_exclusion, $safeguards['force']);
        $appendable = !$ignored && !$not_fixable && !$already_excluded;
        $key = strtolower($suggested_exclusion . '|' . (string) $source . '|' . (string) $symbol);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $fallback_recommended = ($already_force_deferred && !$already_excluded) || ('exclusion' === $preferred_target && !$already_excluded);
        $category = $ignored ? 'ignored' : ($not_fixable ? 'not-fixable' : ($already_excluded ? 'already-listed' : ($fallback_recommended ? 'fallback-candidate' : 'appendable-fix')));
        $category_label = $ignored ? 'Ignored' : ($not_fixable ? 'Not fixable by exclusion' : ($already_excluded ? 'Already listed in Do Not Defer or Delay' : ($fallback_recommended ? 'Do Not Defer or Delay candidate' : 'Appendable fixes')));
        $suggestions[] = array(
            'symbol'             => (string) $symbol,
            'source'             => 'browser-runtime-error',
            'category'           => $category,
            'categoryLabel'      => $category_label,
            'sample'             => substr((string) $message, 0, 500),
            'definingScriptUrl'  => (string) $source,
            'definingHandle'     => '',
            'suggestedExclusion' => $suggested_exclusion,
            'confidence'         => $ignored ? 'ignored' : ($not_fixable ? 'not-fixable' : (string) $confidence),
            'reason'             => (string) $reason,
            'alreadyExcluded'    => $already_excluded,
            'alreadyForceDeferred' => $already_force_deferred,
            'alreadySafeguarded' => ($already_excluded || $already_force_deferred),
            'fallbackRecommended' => $fallback_recommended,
            'preferredTarget'     => $preferred_target,
            'appendable'         => $appendable,
        );
    }

    private function runtime_js_scan_add_evidence_source_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions, array $scripts = array())
    {
        $text = (string) $source . "\n" . (string) $message . "\n" . (string) $detail;
        $candidates = array();
        $candidate_seen = array();
        $push = function ($candidate) use (&$candidates, &$candidate_seen) {
            $candidate = $this->runtime_js_scan_clean_console_candidate((string) $candidate);
            if ('' === $candidate || $this->runtime_js_scan_is_ultracache_runtime_helper_source($candidate)) {
                return;
            }
            $base = $this->runtime_js_scan_basename_from_source($candidate);
            if ('' === $base || $this->runtime_js_scan_is_generic_script_basename($base)) {
                return;
            }
            $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($candidate, 5);
            if ('' === $fragment) {
                return;
            }
            $key = strtolower($fragment);
            if (isset($candidate_seen[$key])) {
                return;
            }
            $candidate_seen[$key] = true;
            $candidates[] = array('source' => $candidate, 'fragment' => $fragment);
        };

        foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
            $push($candidate);
        }
        foreach ($this->runtime_js_scan_console_sources_from_text($text) as $candidate) {
            $push($candidate);
        }

        $added = false;
        foreach ($candidates as $candidate) {
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                (string) $candidate['fragment'],
                'runtime error stack source',
                (string) $candidate['source'],
                $message,
                'The browser runtime error directly identifies this plugin/theme script. No provider could be resolved, so retain this exact owner-relative source as the compatibility fallback and rescan.',
                $exclusions,
                'recommended'
            );
            $added = true;
        }
        return $added;
    }

    private function runtime_js_scan_targeted_source_fragment_from_source($source, $fallback_parts = 4)
    {
        $source = $this->runtime_js_scan_clean_console_candidate($source);
        if ('' === $source) {
            return '';
        }

        $source = html_entity_decode($source, ENT_QUOTES, 'UTF-8');
        $source = preg_replace('/(?::\d+){1,2}$/', '', $source);
        $path = (string) wp_parse_url($source, PHP_URL_PATH);
        if ('' === $path) {
            $path = preg_replace('/[?#].*$/', '', $source);
        }

        $path = trim(strtolower((string) $path), '/');
        if ('' === $path) {
            return '';
        }

        $owner = function_exists('ultracache_plugin_theme_owner_from_public_source') ? ultracache_plugin_theme_owner_from_public_source('/' . $path) : array();
        if (!empty($owner['slug'])) {
            $relative = isset($owner['relative']) ? trim((string) $owner['relative'], '/') : '';
            if ('' === $relative) {
                return $owner['slug'] . '/';
            }
            return sanitize_text_field(substr($owner['slug'] . '/' . $relative, 0, 220));
        }

        if (false !== strpos($path, 'wp-includes/js/')) {
            return '';
        }

        return $this->runtime_js_scan_path_fragment_from_source($source, $fallback_parts);
    }

    private function runtime_js_scan_add_direct_source_review_suggestion(&$suggestions, &$seen, $source, $message, $reason, array $exclusions, $label = 'runtime error direct source')
    {
        $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($source, 4);
        if ('' !== $fragment) {
            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, $label, $source, $message, $reason, $exclusions, 'recommended');
            return;
        }

        $source_base = $this->runtime_js_scan_basename_from_source($source);
        if ('' !== $source_base && !$this->runtime_js_scan_is_generic_script_basename($source_base)) {
            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, $label . ' basename', $source, $message, $reason, $exclusions, 'recommended');
        }
    }

    private function runtime_js_scan_common_owner_directory_fragment($first_source, $second_source)
    {
        $first = $this->runtime_js_scan_owner_group_from_source($first_source);
        $second = $this->runtime_js_scan_owner_group_from_source($second_source);
        if (empty($first) || empty($second)) {
            return '';
        }

        $first_kind = isset($first['kind']) ? (string) $first['kind'] : '';
        $second_kind = isset($second['kind']) ? (string) $second['kind'] : '';
        $first_slug = isset($first['slug']) ? sanitize_key((string) $first['slug']) : '';
        $second_slug = isset($second['slug']) ? sanitize_key((string) $second['slug']) : '';
        if ('' === $first_kind || '' === $first_slug || $first_kind !== $second_kind || $first_slug !== $second_slug) {
            return '';
        }

        $first_relative = isset($first['relative']) ? trim((string) $first['relative'], '/') : '';
        $second_relative = isset($second['relative']) ? trim((string) $second['relative'], '/') : '';
        $first_dirs = explode('/', trim(dirname($first_relative), './'));
        $second_dirs = explode('/', trim(dirname($second_relative), './'));
        $common = array();
        $max = min(count($first_dirs), count($second_dirs), 3);
        for ($i = 0; $i < $max; $i++) {
            $first_part = isset($first_dirs[$i]) ? sanitize_file_name((string) $first_dirs[$i]) : '';
            $second_part = isset($second_dirs[$i]) ? sanitize_file_name((string) $second_dirs[$i]) : '';
            if ('' === $first_part || '.' === $first_part || $first_part !== $second_part) {
                break;
            }
            $common[] = $first_part;
        }

        if (empty($common)) {
            return '';
        }

        return sanitize_text_field(substr($first_slug . '/' . implode('/', $common) . '/', 0, 220));
    }

    private function runtime_js_scan_add_same_owner_directory_suggestions(&$suggestions, &$seen, array $direct_sources, $provider_source, $message, $symbol, $reason, array $exclusions)
    {
        $provider_source = $this->runtime_js_scan_clean_console_candidate($provider_source);
        if ('' === $provider_source || empty($direct_sources)) {
            return false;
        }

        $added = false;
        foreach ($direct_sources as $direct) {
            $direct_source = isset($direct['source']) ? (string) $direct['source'] : '';
            if ('' === $direct_source) {
                continue;
            }

            $fragment = $this->runtime_js_scan_common_owner_directory_fragment($direct_source, $provider_source);
            if ('' === $fragment) {
                continue;
            }

            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $fragment,
                'same-owner JS dependency group: ' . sanitize_text_field((string) $symbol),
                $provider_source,
                $message,
                $reason,
                $exclusions,
                'recommended'
            );
            $added = true;
        }

        return $added;
    }

    private function runtime_js_scan_add_known_specific_error_group_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions)
    {
        // Intentionally disabled. JS error suggestions must be discovery-only: direct stack sources,
        // final HTML inventory matches for those exact sources, and active plugin/theme code search.
        return false;
    }

    private function runtime_js_scan_owner_group_from_source($source)
    {
        $source = $this->runtime_js_scan_clean_console_candidate($source);
        if ('' === $source) {
            return array();
        }

        $decoded = html_entity_decode((string) $source, ENT_QUOTES, 'UTF-8');
        $decoded = preg_replace('/(?::\d+){1,2}$/', '', (string) $decoded);
        $path = (string) wp_parse_url($decoded, PHP_URL_PATH);
        if ('' === $path) {
            $path = preg_replace('/[?#].*$/', '', (string) $decoded);
        }

        $path = trim(strtolower((string) $path), '/');
        if ('' === $path) {
            return array();
        }

        $owner = function_exists('ultracache_plugin_theme_owner_from_public_source') ? ultracache_plugin_theme_owner_from_public_source('/' . $path) : array();
        if (empty($owner['kind']) || empty($owner['slug'])) {
            return array();
        }

        return array(
            'kind'     => sanitize_text_field((string) $owner['kind']),
            'slug'     => sanitize_key((string) $owner['slug']),
            'group'    => sanitize_text_field((string) $owner['group']),
            'relative' => sanitize_text_field(substr((string) $owner['relative'], 0, 220)),
            'source'   => sanitize_text_field(substr((string) $decoded, 0, 300)),
        );
    }

    private function runtime_js_scan_source_candidates_from_error($source, $message, $detail)
    {
        $candidates = array();
        $seen = array();

        $push = static function ($candidate) use (&$candidates, &$seen) {
            $candidate = trim((string) $candidate);
            if ('' === $candidate) {
                return;
            }
            $candidate = html_entity_decode($candidate, ENT_QUOTES, 'UTF-8');
            $candidate = preg_replace('/[\s\)\]\}"\'<>;,]+$/', '', (string) $candidate);
            $candidate = preg_replace('/(?::\d+){1,2}$/', '', (string) $candidate);
            $candidate = preg_replace('/[?#].*$/', '', (string) $candidate);
            $candidate = trim((string) $candidate);
            if ('' === $candidate) {
                return;
            }
            $key = strtolower($candidate);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $candidates[] = $candidate;
        };

        $push($source);

        $haystack = (string) $source . "\n" . (string) $message . "\n" . (string) $detail;
        if (preg_match_all('#(?:https?:)?//[^\s\)\]\}"\'<>]+#i', $haystack, $matches)) {
            foreach ((array) $matches[0] as $candidate) {
                $push($candidate);
            }
        }
        $dynamic_root_markers = array();
        if (function_exists('ultracache_plugins_public_path')) {
            $dynamic_root_markers[] = ultracache_plugins_public_path();
        }
        if (function_exists('ultracache_themes_public_paths')) {
            $dynamic_root_markers = array_merge($dynamic_root_markers, ultracache_themes_public_paths());
        }
        foreach (array_filter($dynamic_root_markers) as $marker) {
            $quoted = preg_quote(rtrim((string) $marker, '/'), '#');
            if ('' !== $quoted && preg_match_all('#' . $quoted . '/[^\s\)\]\}"\'<>]+#i', $haystack, $path_matches)) {
                foreach ((array) $path_matches[0] as $candidate) {
                    $push($candidate);
                }
            }
        }

        return $candidates;
    }

    private function runtime_js_scan_add_runtime_error_group_resolver_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions)
    {
        // Intentionally disabled. Owner/group suggestions are produced only by the strict
        // discovery resolver when the current error stack and code search prove the relationship.
        return false;
    }

    private function runtime_js_scan_basename_from_source($source)
    {
        $source = $this->runtime_js_scan_clean_console_candidate($source);
        if ('' === $source) {
            return '';
        }

        $source = html_entity_decode($source, ENT_QUOTES, 'UTF-8');
        $source = preg_replace('/(?::\d+){1,2}$/', '', $source);
        $path = (string) wp_parse_url($source, PHP_URL_PATH);
        if ('' === $path) {
            $path = preg_replace('/[?#].*$/', '', $source);
        }
        $base = basename($path);
        return sanitize_text_field($base);
    }

    private function runtime_js_scan_is_generic_script_basename($basename)
    {
        $basename = strtolower(trim((string) $basename));
        if ('' === $basename) {
            return true;
        }

        return in_array($basename, array(
            'jquery.js',
            'jquery.min.js',
            'jquery-migrate.js',
            'jquery-migrate.min.js',
            'i18n.js',
            'i18n.min.js',
            'hooks.js',
            'hooks.min.js',
            'api-fetch.js',
            'api-fetch.min.js',
            'main.js',
            'main.min.js',
            'functions.js',
            'functions.min.js',
            'function.js',
            'function.min.js',
            'scripts.js',
            'scripts.min.js',
            'script.js',
            'script.min.js',
            'custom.js',
            'custom.min.js',
            'app.js',
            'app.min.js',
            'index.js',
            'index.min.js',
            'site.js',
            'site.min.js',
            'frontend.js',
            'frontend.min.js',
            'public.js',
            'public.min.js',
            'plugin.js',
            'plugin.min.js',
        ), true);
    }

    private function runtime_js_scan_path_fragment_from_source($source, $parts = 4)
    {
        $source = $this->runtime_js_scan_clean_console_candidate($source);
        if ('' === $source) {
            return '';
        }

        $source = html_entity_decode($source, ENT_QUOTES, 'UTF-8');
        $source = preg_replace('/(?::\d+){1,2}$/', '', $source);
        $path = (string) wp_parse_url($source, PHP_URL_PATH);
        if ('' === $path) {
            $path = preg_replace('/[?#].*$/', '', $source);
        }

        $path = trim((string) $path, '/');
        if ('' === $path || false === stripos($path, '.js')) {
            return '';
        }

        $segments = array_values(array_filter(explode('/', strtolower($path)), 'strlen'));
        if (empty($segments)) {
            return '';
        }

        $parts = max(2, min(6, (int) $parts));
        $fragment = implode('/', array_slice($segments, -1 * min($parts, count($segments))));
        $base = basename($fragment);
        $owner = function_exists('ultracache_plugin_theme_owner_from_public_source') ? ultracache_plugin_theme_owner_from_public_source('/' . trim((string) $path, '/')) : array();
        $is_targeted_local_asset = !empty($owner['slug']);
        if ($this->runtime_js_scan_is_generic_script_basename($base) && !$is_targeted_local_asset) {
            return '';
        }

        return sanitize_text_field($fragment);
    }

    private function runtime_js_scan_service_fragment_from_source($source, $global = '')
    {
        $source = html_entity_decode((string) $source, ENT_QUOTES, 'UTF-8');
        $source = preg_replace('/(?::\d+){1,2}$/', '', $source);
        $source = trim((string) $source);
        if ('' === $source) {
            return '';
        }

        $parts = wp_parse_url($source);
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $path = isset($parts['path']) ? trim(strtolower((string) $parts['path']), '/') : '';
        if ('' === $host || '' === $path || false !== stripos($path, '.css')) {
            return '';
        }

        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $site_host = strtolower((string) wp_parse_url(site_url('/'), PHP_URL_HOST));
        if ($host === $home_host || $host === $site_host) {
            return '';
        }

        $global = strtolower(trim((string) $global));
        if ('' !== $global && !$this->runtime_js_scan_is_generic_token($global)) {
            $haystack = $host . '/' . $path;
            if (false === strpos($haystack, $global)) {
                return '';
            }
        }

        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        if (empty($segments)) {
            return '';
        }
        $path_fragment = implode('/', array_slice($segments, -1 * min(3, count($segments))));
        $fragment = $host . '/' . $path_fragment;
        return sanitize_text_field(substr($fragment, 0, 220));
    }

    private function runtime_js_scan_is_explicit_missing_global($symbol)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol) {
            return false;
        }
        $normalized = strtolower(str_replace(array('window.', 'globalThis.'), '', $symbol));
        return in_array($normalized, array(
            'jquery',
            '$',
            '_',
            'underscore',
            'wp',
            'wp.i18n',
            'wp.hooks',
            'wp.template',
            'wp.apifetch',
            'wp.domready',
        ), true);
    }

    private function runtime_js_scan_is_explicit_missing_global_provider_path($path, $symbol)
    {
        $path = strtolower(trim((string) $path));
        $symbol = strtolower(str_replace(array('window.', 'globalthis.'), '', trim((string) $symbol)));
        if (false !== strpos($symbol, 'jquery-migrate')) {
            $symbol = 'jquery-migrate';
        } elseif (false !== strpos($symbol, 'jquery')) {
            $symbol = 'jquery';
        } elseif (false !== strpos($symbol, 'underscore')) {
            $symbol = 'underscore';
        } elseif (false !== strpos($symbol, 'wp.template')) {
            $symbol = 'wp.template';
        } elseif (false !== strpos($symbol, 'wp.i18n')) {
            $symbol = 'wp.i18n';
        } elseif (false !== strpos($symbol, 'wp.hooks')) {
            $symbol = 'wp.hooks';
        } elseif (false !== strpos($symbol, 'wp.apifetch')) {
            $symbol = 'wp.apifetch';
        } elseif (false !== strpos($symbol, 'wp.domready')) {
            $symbol = 'wp.domready';
        }
        if ('' === $path || '' === $symbol) {
            return false;
        }
        if ('jquery-migrate' === $symbol) {
            return false !== strpos($path, 'jquery/jquery-migrate.js')
                || false !== strpos($path, 'jquery/jquery-migrate.min.js')
                || false !== strpos($path, '/jquery-migrate.js')
                || false !== strpos($path, '/jquery-migrate.min.js')
                || false !== strpos($path, 'jquery-migrate-js');
        }
        if (in_array($symbol, array('jquery', '$'), true)) {
            return false !== strpos($path, 'jquery/jquery.js')
                || false !== strpos($path, 'jquery/jquery.min.js')
                || false !== strpos($path, '/jquery.js')
                || false !== strpos($path, '/jquery.min.js')
                || false !== strpos($path, 'jquery-core-js');
        }
        if (in_array($symbol, array('_', 'underscore'), true)) {
            return false !== strpos($path, 'underscore.js') || false !== strpos($path, 'underscore.min.js') || false !== strpos($path, 'underscore-js');
        }
        if ('wp.i18n' === $symbol) {
            return false !== strpos($path, 'dist/i18n.js') || false !== strpos($path, 'dist/i18n.min.js') || false !== strpos($path, 'wp-i18n-js');
        }
        if ('wp.hooks' === $symbol) {
            return false !== strpos($path, 'dist/hooks.js') || false !== strpos($path, 'dist/hooks.min.js') || false !== strpos($path, 'wp-hooks-js');
        }
        if ('wp.apifetch' === $symbol) {
            return false !== strpos($path, 'dist/api-fetch.js') || false !== strpos($path, 'dist/api-fetch.min.js') || false !== strpos($path, 'wp-api-fetch-js');
        }
        if ('wp.domready' === $symbol) {
            return false !== strpos($path, 'dist/dom-ready.js') || false !== strpos($path, 'dist/dom-ready.min.js') || false !== strpos($path, 'wp-dom-ready-js');
        }
        if (in_array($symbol, array('wp', 'wp.template'), true)) {
            return false !== strpos($path, 'wp-util.js') || false !== strpos($path, 'wp-util.min.js') || false !== strpos($path, 'wp-util-js');
        }
        return false;
    }

    private function runtime_js_scan_wp_provider_handles_for_missing_global($symbol)
    {
        $symbol = strtolower(str_replace(array('window.', 'globalthis.'), '', trim((string) $symbol)));
        if ('$' === $symbol || 'jquery' === $symbol) {
            return array('jquery-core', 'jquery');
        }
        if ('_' === $symbol || 'underscore' === $symbol) {
            return array('underscore');
        }
        if ('wp.template' === $symbol) {
            return array('wp-util');
        }
        if ('wp.i18n' === $symbol) {
            return array('wp-i18n');
        }
        if ('wp.hooks' === $symbol) {
            return array('wp-hooks');
        }
        if ('wp.apifetch' === $symbol) {
            return array('wp-api-fetch');
        }
        if ('wp.domready' === $symbol) {
            return array('wp-dom-ready');
        }
        return array();
    }

    private function runtime_js_scan_registered_script_fragment_for_handle($handle, $symbol = '', array $visited = array())
    {
        $handle = sanitize_key((string) $handle);
        if ('' === $handle || isset($visited[$handle]) || !function_exists('wp_scripts')) {
            return '';
        }
        $visited[$handle] = true;

        $wp_scripts = wp_scripts();
        if (!is_object($wp_scripts) || empty($wp_scripts->registered[$handle]) || !is_object($wp_scripts->registered[$handle])) {
            return '';
        }

        $registered = $wp_scripts->registered[$handle];
        $src = isset($registered->src) ? (string) $registered->src : '';
        if ('' !== $src) {
            if (0 === strpos($src, '//')) {
                $src = (is_ssl() ? 'https:' : 'http:') . $src;
            } elseif (0 === strpos($src, '/')) {
                $src = home_url($src);
            } elseif (!preg_match('#^https?://#i', $src)) {
                $base_url = isset($wp_scripts->base_url) ? (string) $wp_scripts->base_url : includes_url();
                $src = trailingslashit($base_url) . ltrim($src, '/');
            }

            $fragment = $this->runtime_js_scan_provider_path_fragment_from_source($src, $symbol);
            if ('' === $fragment) {
                $fragment = $this->runtime_js_scan_path_fragment_from_source($src, 6);
            }
            if ('' !== $fragment) {
                return $fragment;
            }
        }

        foreach ((array) ($registered->deps ?? array()) as $dependency) {
            $fragment = $this->runtime_js_scan_registered_script_fragment_for_handle($dependency, $symbol, $visited);
            if ('' !== $fragment) {
                return $fragment;
            }
        }

        return '';
    }

    private function runtime_js_scan_is_actionable_missing_symbol($symbol)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol) {
            return false;
        }
        if ($this->runtime_js_scan_is_explicit_missing_global($symbol)) {
            return true;
        }
        if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)?$/', $symbol)) {
            return false;
        }
        if ($this->runtime_js_scan_is_generic_token($symbol)) {
            return false;
        }
        return strlen(preg_replace('/[^A-Za-z0-9]+/', '', $symbol)) >= 4;
    }

    private function runtime_js_scan_dependency_identity_token($value)
    {
        $value = strtolower(html_entity_decode(trim((string) $value), ENT_QUOTES, 'UTF-8'));
        $value = preg_replace('/(?::\d+){1,2}$/', '', $value);
        $value = preg_replace('/[?#].*$/', '', $value);
        $value = basename(str_replace('\\', '/', $value));
        $value = preg_replace('/\.(?:min\.)?(?:js|mjs)$/', '', (string) $value);
        $value = preg_replace('/(?:[-_.]js)$/', '', (string) $value);
        return preg_replace('/[^a-z0-9]+/', '', (string) $value);
    }

    private function runtime_js_scan_provider_identity_matches_symbol($identity, $symbol)
    {
        $symbol = trim((string) $symbol);
        if (!$this->runtime_js_scan_is_actionable_missing_symbol($symbol)) {
            return false;
        }
        if ($this->runtime_js_scan_is_explicit_missing_global_provider_path($identity, $symbol)) {
            return true;
        }

        $symbol_parts = array_filter(explode('.', strtolower(str_replace(array('window.', 'globalThis.'), '', $symbol))), 'strlen');
        $symbol_last = !empty($symbol_parts) ? end($symbol_parts) : $symbol;
        $symbol_token = preg_replace('/[^a-z0-9]+/', '', strtolower((string) $symbol_last));
        if (strlen($symbol_token) < 4) {
            return false;
        }

        $identity = html_entity_decode((string) $identity, ENT_QUOTES, 'UTF-8');
        foreach ((array) preg_split('/[\s|]+/', $identity) as $part) {
            $token = $this->runtime_js_scan_dependency_identity_token($part);
            if ('' === $token) {
                continue;
            }
            if ($token === $symbol_token) {
                return true;
            }
        }
        return false;
    }

    private function runtime_js_scan_wp_provider_fragment_for_missing_global($symbol)
    {
        foreach ($this->runtime_js_scan_wp_provider_handles_for_missing_global($symbol) as $handle) {
            $fragment = $this->runtime_js_scan_registered_script_fragment_for_handle($handle, $symbol);
            if ('' !== $fragment) {
                return $fragment;
            }
        }

        return $this->runtime_js_scan_wp_core_provider_fragment_fallback($symbol);
    }

    /**
     * Resolve well-known WordPress core dependency providers with WordPress URL
     * helpers only when the script registry did not return a registered source.
     *
     * This is not a broad default list. It is only used after a browser error
     * explicitly names the missing dependency, for example "_ is not defined".
     */
    private function runtime_js_scan_wp_core_provider_fragment_fallback($symbol)
    {
        $symbol = strtolower(str_replace(array('window.', 'globalthis.'), '', trim((string) $symbol)));
        if ('' === $symbol || !function_exists('includes_url')) {
            return '';
        }

        $relative = '';
        if ('_' === $symbol || 'underscore' === $symbol) {
            $relative = 'js/underscore.min.js';
        } elseif ('$' === $symbol || 'jquery' === $symbol) {
            $relative = 'js/jquery/jquery.min.js';
        } elseif ('jquery-migrate' === $symbol) {
            $relative = 'js/jquery/jquery-migrate.min.js';
        } elseif ('wp.template' === $symbol || 'wp' === $symbol) {
            $relative = 'js/wp-util.min.js';
        } elseif ('wp.i18n' === $symbol) {
            $relative = 'js/dist/i18n.min.js';
        } elseif ('wp.hooks' === $symbol) {
            $relative = 'js/dist/hooks.min.js';
        } elseif ('wp.apifetch' === $symbol) {
            $relative = 'js/dist/api-fetch.min.js';
        } elseif ('wp.domready' === $symbol) {
            $relative = 'js/dist/dom-ready.min.js';
        }

        if ('' === $relative) {
            return '';
        }

        return $this->runtime_js_scan_provider_path_fragment_from_source(includes_url($relative), $symbol);
    }

    private function runtime_js_scan_add_explicit_wp_dependency_suggestions_from_text(&$suggestions, &$seen, $message, $detail, array $exclusions)
    {
        $text = (string) $message . "\n" . (string) $detail;
        if ('' === trim($text)) {
            return false;
        }

        $symbols = array();
        if (preg_match('/(?:ReferenceError:\s*)?_\s+is\s+not\s+defined/i', $text)) {
            $symbols['_'] = '_';
        }
        if (preg_match('/(?:ReferenceError:\s*)?(?:jQuery|\$)\s+is\s+not\s+defined/i', $text)) {
            $symbols['jquery'] = 'jQuery';
        }
        if (preg_match('/(?:TypeError:\s*)?wp\.template\s+is\s+not\s+a\s+function/i', $text)) {
            $symbols['wp.template'] = 'wp.template';
        }
        if (preg_match('/(?:ReferenceError:\s*)?wp\.i18n\s+is\s+not\s+defined/i', $text)) {
            $symbols['wp.i18n'] = 'wp.i18n';
        }
        if (preg_match('/(?:ReferenceError:\s*)?wp\.hooks\s+is\s+not\s+defined/i', $text)) {
            $symbols['wp.hooks'] = 'wp.hooks';
        }
        if (preg_match('/(?:ReferenceError:\s*)?wp\.apiFetch\s+is\s+not\s+defined/i', $text)) {
            $symbols['wp.apifetch'] = 'wp.apiFetch';
        }
        if (preg_match('/(?:ReferenceError:\s*)?wp\.domReady\s+is\s+not\s+defined/i', $text)) {
            $symbols['wp.domready'] = 'wp.domReady';
        }

        $added = false;
        foreach ($symbols as $lookup_symbol => $display_symbol) {
            $provider = $this->runtime_js_scan_wp_provider_fragment_for_missing_global($lookup_symbol);
            if ('' === $provider) {
                continue;
            }

            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $provider,
                $display_symbol,
                $provider,
                (string) $message,
                'The browser error explicitly names the missing WordPress dependency "' . sanitize_text_field($display_symbol) . '". UltraCache resolved the exact provider through the WordPress script registry or WordPress core URL helpers. Prefer Defer Instead of Delay for the provider/consumer pair, then use Do Not Defer or Delay as the compatibility fallback.',
                $exclusions,
                'recommended'
            );
            $added = true;
        }

        return $added;
    }

    private function runtime_js_scan_file_uses_missing_symbol($content, $symbol)
    {
        $content = (string) $content;
        $symbol = trim((string) $symbol);
        if ('' === $content || '' === $symbol) {
            return false;
        }
        $normalized = strtolower(str_replace(array('window.', 'globalThis.'), '', $symbol));
        if (in_array($normalized, array('jquery', '$'), true)) {
            return (bool) preg_match('/(?:^|[^A-Za-z0-9_$])(?:jQuery|\$)\s*(?:\.|\(|\[|;|,|\))/m', $content)
                || false !== strpos($content, 'window.jQuery');
        }
        if (in_array($normalized, array('_', 'underscore'), true)) {
            return (bool) preg_match('/(?:^|[^A-Za-z0-9_$])_\s*(?:\.|\(|\[)/m', $content);
        }
        $quoted = preg_quote($symbol, '/');
        if (false !== strpos($symbol, '.')) {
            return (bool) preg_match('/(?:^|[^A-Za-z0-9_$])' . $quoted . '\s*(?:\.|\(|\[|;|,|\))/m', $content);
        }
        return (bool) preg_match('/(?:^|[^A-Za-z0-9_$])' . $quoted . '\s*(?:\.|\(|\[|;|,|\))/m', $content);
    }

    private function runtime_js_scan_source_uses_missing_symbol($source, $symbol, array $scripts = array())
    {
        $source = $this->runtime_js_scan_sanitize_source((string) $source);
        $symbol = trim((string) $symbol);
        if ('' === $source || '' === $symbol) {
            return false;
        }

        foreach ($this->runtime_js_scan_find_scripts_by_source_hint($source, $scripts) as $script) {
            $content = $this->runtime_js_scan_script_content($script);
            if ('' !== $content && $this->runtime_js_scan_file_uses_missing_symbol($content, $symbol)) {
                return true;
            }
        }

        $content = $this->runtime_js_scan_read_local_script_content($source);
        return '' !== $content && $this->runtime_js_scan_file_uses_missing_symbol($content, $symbol);
    }

    private function runtime_js_scan_provider_path_fragment_from_source($source, $symbol)
    {
        $source = $this->runtime_js_scan_clean_console_candidate($source);
        if ('' === $source || !$this->runtime_js_scan_provider_identity_matches_symbol($source, $symbol)) {
            return '';
        }
        return $this->runtime_js_scan_targeted_source_fragment_from_source($source, 5);
    }

    private function runtime_js_scan_find_provider_scripts_for_missing_global($symbol, array $scripts)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol || empty($scripts)) {
            return array();
        }
        $providers = array();
        $seen = array();
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $src = isset($script['src']) ? (string) $script['src'] : '';
            $id = isset($script['id']) ? (string) $script['id'] : '';
            $handle = isset($script['handle']) ? (string) $script['handle'] : '';
            $haystack = $src . ' ' . $id . ' ' . $handle;
            if (!$this->runtime_js_scan_provider_identity_matches_symbol($haystack, $symbol)) {
                continue;
            }
            $key = strtolower($src . '|' . $id . '|' . $handle);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $providers[] = array(
                'src'    => $src,
                'id'     => $id,
                'handle' => $handle,
            );
            if (count($providers) >= 6) {
                break;
            }
        }
        return $providers;
    }

    private function runtime_js_scan_find_scripts_defining_symbol_text($symbol, array $scripts)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
            return array();
        }

        $matches = array();
        $seen = array();
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }

            $src = isset($script['src']) ? (string) $script['src'] : '';
            $id = isset($script['id']) ? (string) $script['id'] : '';
            $handle = isset($script['handle']) ? (string) $script['handle'] : '';
            $content = $this->runtime_js_scan_script_content($script);
            $identity_match = $this->runtime_js_scan_provider_identity_matches_symbol($src . ' ' . $id . ' ' . $handle, $symbol);
            if (!$identity_match && ('' === $content || !$this->runtime_js_scan_file_defines_symbol($content, $symbol))) {
                continue;
            }

            $key = strtolower($src . '|' . $id . '|' . $handle . '|' . $symbol);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $matches[] = array(
                'src'    => $src,
                'id'     => $id,
                'handle' => $handle,
            );
            if (count($matches) >= 8) {
                break;
            }
        }
        return $matches;
    }

    private function runtime_js_scan_add_inventory_symbol_provider_suggestions(&$suggestions, &$seen, $symbol, array $scripts, $message, array $exclusions)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
            return false;
        }

        $providers = $this->runtime_js_scan_find_scripts_defining_symbol_text($symbol, $scripts);
        if (empty($providers)) {
            return false;
        }

        $added = false;
        foreach ($providers as $provider) {
            $this->runtime_js_scan_add_script_identity_suggestions(
                $suggestions,
                $seen,
                $provider,
                'scanned HTML/global provider',
                isset($provider['src']) ? (string) $provider['src'] : '',
                $message,
                'Runtime Scan found the missing global "' . sanitize_text_field($symbol) . '" in the browser error and found a scanned HTML script block or loaded local script that defines that same global. Keep that provider out of Delay/Defer so the dependent code can execute in order.',
                $exclusions,
                'recommended',
                $symbol
            );
            $added = true;
        }
        return $added;
    }

    private function runtime_js_scan_add_missing_global_provider_suggestions(&$suggestions, &$seen, $symbol, array $direct_sources, array $scripts, $message, array $exclusions)
    {
        $symbol = trim((string) $symbol);
        $symbol_lc = strtolower($symbol);
        if ('' === $symbol || ('jquery-migrate' !== $symbol_lc && !$this->runtime_js_scan_is_actionable_missing_symbol($symbol))) {
            return false;
        }

        $evidence_sources = array();
        foreach ($direct_sources as $direct) {
            $direct_source = isset($direct['source']) ? (string) $direct['source'] : '';
            if ('' === $direct_source) {
                continue;
            }
            if ($this->runtime_js_scan_source_uses_missing_symbol($direct_source, $symbol, $scripts)) {
                $evidence_sources[] = $direct;
            }
        }

        $core_provider_fragment = $this->runtime_js_scan_wp_provider_fragment_for_missing_global($symbol);
        if ('' !== $core_provider_fragment) {
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $core_provider_fragment,
                sanitize_text_field($symbol),
                $core_provider_fragment,
                $message,
                'The browser error explicitly says the global "' . sanitize_text_field($symbol) . '" is missing. UltraCache resolved that exact missing dependency through the WordPress script registry. Prefer Defer Instead of Delay for the dependency pair, then use Do Not Defer or Delay as the compatibility fallback.',
                $exclusions,
                'recommended'
            );
            return true;
        }

        $providers = $this->runtime_js_scan_find_provider_scripts_for_missing_global($symbol, $scripts);
        if (empty($providers)) {
            return false;
        }

        $added = false;
        $evidence_fragments = array();
        foreach ($evidence_sources as $direct) {
            if (!empty($direct['fragment'])) {
                $evidence_fragments[] = (string) $direct['fragment'];
            }
        }
        $evidence_text = !empty($evidence_fragments) ? implode(', ', array_unique($evidence_fragments)) : 'the browser error stack';
        foreach ($providers as $provider) {
            $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
            $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
            $provider_fragment = $this->runtime_js_scan_provider_path_fragment_from_source($provider_src, $symbol);
            if ('' === $provider_fragment) {
                $provider_fragment = $this->runtime_js_scan_path_fragment_from_source($provider_src, 6);
            }
            if ('' !== $provider_fragment) {
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $provider_fragment,
                    'explicit missing global provider: ' . sanitize_text_field($symbol),
                    $provider_src,
                    $message,
                    'The browser error explicitly says the global "' . sanitize_text_field($symbol) . '" is missing. Runtime Scan used ' . sanitize_text_field($evidence_text) . ' and matched the loaded provider script from the final page inventory. Prefer Defer Instead of Delay for the matched provider and consumer; no broad core dependency list was inferred.',
                    $exclusions,
                    'recommended'
                );
                $added = true;
            } elseif ('' !== $provider_id) {
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $provider_id,
                    'explicit missing global provider handle: ' . sanitize_text_field($symbol),
                    $provider_src,
                    $message,
                    'The browser error explicitly says the global "' . sanitize_text_field($symbol) . '" is missing, and the final page inventory matched this provider handle/id.',
                    $exclusions,
                    'recommended'
                );
                $added = true;
            }
        }
        return $added;
    }

    private function runtime_js_scan_add_missing_global_consumer_suggestions(&$suggestions, &$seen, $symbol, $source, $message, $detail, array $scripts, array $exclusions)
    {
        $symbol = trim((string) $symbol);
        $symbol_lc = strtolower($symbol);
        if ('' === $symbol || ('jquery-migrate' !== $symbol_lc && !$this->runtime_js_scan_is_actionable_missing_symbol($symbol))) {
            return false;
        }

        $text = (string) $source . "\n" . (string) $message . "\n" . (string) $detail;
        $candidates = array();
        $candidate_seen = array();
        $push = function ($candidate) use (&$candidates, &$candidate_seen, $symbol, $symbol_lc) {
            $candidate = $this->runtime_js_scan_clean_console_candidate((string) $candidate);
            if ('' === $candidate || $this->runtime_js_scan_is_ultracache_runtime_helper_source($candidate)) {
                return;
            }
            $base = $this->runtime_js_scan_basename_from_source($candidate);
            if ('' === $base || !preg_match('/\.js$/i', $base)) {
                return;
            }
            if ($this->runtime_js_scan_provider_identity_matches_symbol($candidate, $symbol) || ('jquery-migrate' === $symbol_lc && false !== stripos($candidate, 'jquery-migrate'))) {
                return;
            }
            $key = strtolower($candidate);
            if (isset($candidate_seen[$key])) {
                return;
            }
            $candidate_seen[$key] = true;
            $candidates[] = $candidate;
        };

        foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
            $push($candidate);
        }
        foreach ($this->runtime_js_scan_console_sources_from_text($text) as $candidate) {
            $push($candidate);
        }

        foreach ($candidates as $candidate) {
            $matched_scripts = $this->runtime_js_scan_find_scripts_by_source_hint($candidate, $scripts);
            foreach ($matched_scripts as $script) {
                $script_src = isset($script['src']) ? (string) $script['src'] : '';
                if ('' === $script_src || $this->runtime_js_scan_is_ultracache_runtime_helper_source($script_src) || $this->runtime_js_scan_provider_identity_matches_symbol($script_src, $symbol) || ('jquery-migrate' === $symbol_lc && false !== stripos($script_src, 'jquery-migrate'))) {
                    continue;
                }
                $content = $this->runtime_js_scan_script_content($script);
                if ('' !== $content && !$this->runtime_js_scan_file_uses_missing_symbol($content, $symbol)) {
                    continue;
                }
                $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($script_src, 5);
                if ('' === $fragment) {
                    continue;
                }
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $fragment,
                    'missing global consumer: ' . sanitize_text_field($symbol),
                    $script_src,
                    $message,
                    'The browser error says the global "' . sanitize_text_field($symbol) . '" is missing, and this is the first stack-frame script that actually consumes it. Keep the provider and this consumer in the same execution strategy.',
                    $exclusions,
                    'recommended'
                );
                return true;
            }

            if ($candidate !== $source && !$this->runtime_js_scan_source_uses_missing_symbol($candidate, $symbol, $scripts)) {
                continue;
            }
            $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($candidate, 5);
            if ('' === $fragment) {
                continue;
            }
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $fragment,
                'missing global consumer: ' . sanitize_text_field($symbol),
                $candidate,
                $message,
                'The browser error says the global "' . sanitize_text_field($symbol) . '" is missing, and this stack-frame script consumed it before the provider was available. Keep both scripts in the same execution strategy.',
                $exclusions,
                'recommended'
            );
            return true;
        }

        return false;
    }

    private function runtime_js_scan_add_jquery_migrate_dependency_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $scripts, array $exclusions)
    {
        $text = (string) $source . "\n" . (string) $message . "\n" . (string) $detail;
        if (!preg_match('/indexOf\s+is\s+not\s+a\s+function/i', $text) || !preg_match('/(?:ce|jQuery|\$)\.fn\.load|\.load\s*@|\.load\s*\(/i', $text)) {
            return false;
        }

        $added = false;
        $provider = function_exists('includes_url') ? $this->runtime_js_scan_provider_path_fragment_from_source(includes_url('js/jquery/jquery-migrate.min.js'), 'jquery-migrate') : '';
        if ('' === $provider) {
            $provider = 'wp-includes/js/jquery/jquery-migrate.min.js';
        }
        if ('' !== $provider) {
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $provider,
                'jquery-migrate',
                $provider,
                $message,
                'The error pattern matches old jQuery event shorthand code, commonly $(window).load(function...). jQuery Migrate provides the compatibility layer; keep it in the same speed-safe execution group as the theme/plugin script that uses the old shorthand.',
                $exclusions,
                'recommended'
            );
            $added = true;
        }

        $added_consumer = $this->runtime_js_scan_add_missing_global_consumer_suggestions(
            $suggestions,
            $seen,
            'jquery-migrate',
            $source,
            $message,
            $detail,
            $scripts,
            $exclusions
        );

        return $added || $added_consumer;
    }

    private function runtime_js_scan_add_duplicate_execution_warning(&$suggestions, &$seen, $source, $message, $detail, array $exclusions)
    {
        $text = (string) $source . "\n" . (string) $message . "\n" . (string) $detail;
        if (!preg_match('/Identifier\s+[\'"][^\'"]+[\'"]\s+has\s+already\s+been\s+declared/i', $text) && !preg_match('/\bVM\d+\b[\s\S]+?\bVM\d+\b/i', $text)) {
            return false;
        }

        $candidates = $this->runtime_js_scan_console_sources_from_text($text);
        if (empty($candidates) && '' !== trim((string) $source)) {
            $candidates = array((string) $source);
        }

        $added = false;
        foreach ($candidates as $candidate) {
            if ($this->runtime_js_scan_is_ultracache_runtime_helper_source($candidate)) {
                continue;
            }
            $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($candidate, 5);
            if ('' === $fragment) {
                $fragment = $this->runtime_js_scan_path_fragment_from_source($candidate, 5);
            }
            if ('' === $fragment) {
                $fragment = $this->runtime_js_scan_basename_from_source($candidate);
            }
            if ('' === $fragment) {
                continue;
            }
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $fragment,
                'duplicate execution',
                $candidate,
                $message,
                'This looks like duplicate execution, not a simple missing dependency. A script or inline block appears to have run twice, often after mixing delayed placeholders, restored scripts, consent rewrites, or stale cached HTML. Purge cache and retest before adding new exclusions; if it persists, keep the whole owner/dependency group in one execution strategy.',
                $exclusions,
                'not-fixable'
            );
            $added = true;
        }

        return $added;
    }

    private function runtime_js_scan_is_inline_extra_handle_suggestion($suggestion)
    {
        $suggestion = strtolower(trim((string) $suggestion));
        return '' !== $suggestion && (bool) preg_match('/-js-(?:extra|before|after)$/', $suggestion);
    }

    private function runtime_js_scan_suggestion_base_token($suggestion)
    {
        $suggestion = strtolower(trim((string) $suggestion));
        $suggestion = preg_replace('/-js-(?:extra|before|after)$/', '', $suggestion);
        $suggestion = preg_replace('/-js$/', '', (string) $suggestion);
        $suggestion = preg_replace('/[^a-z0-9_-]+/', '', (string) $suggestion);
        return (string) $suggestion;
    }

    private function runtime_js_scan_canonical_suggestion_identity($suggestion)
    {
        $suggestion = strtolower(html_entity_decode(trim((string) $suggestion), ENT_QUOTES, 'UTF-8'));
        $suggestion = preg_replace('/(?::\d+){1,2}$/', '', $suggestion);
        $suggestion = preg_replace('/[?#].*$/', '', (string) $suggestion);
        $suggestion = trim(str_replace('\\', '/', (string) $suggestion), '/');
        if (preg_match('#(?:^|/)wp-content/(?:themes|plugins)/([^/]+)/(.*)$#', $suggestion, $matches)) {
            return trim($matches[1] . '/' . $matches[2], '/');
        }
        if (preg_match('#(?:^|/)(?:themes|plugins)/([^/]+)/(.*)$#', $suggestion, $matches)) {
            return trim($matches[1] . '/' . $matches[2], '/');
        }
        return $suggestion;
    }

    private function runtime_js_scan_finalize_suggestions(array $suggestions)
    {
        $path_items = array();
        foreach ($suggestions as $item) {
            if (!is_array($item)) {
                continue;
            }
            $line = isset($item['suggestedExclusion']) ? strtolower(trim((string) $item['suggestedExclusion'])) : '';
            $source = isset($item['definingScriptUrl']) ? strtolower(trim((string) $item['definingScriptUrl'])) : '';
            if ('' === $line || false === strpos($line, '/')) {
                continue;
            }
            $path_items[] = array(
                'line'   => $line,
                'source' => $source,
                'base'   => $this->runtime_js_scan_suggestion_base_token(basename($line)),
            );
        }

        $out = array();
        $seen_final = array();
        foreach ($suggestions as $item) {
            if (!is_array($item)) {
                continue;
            }
            $line = isset($item['suggestedExclusion']) ? strtolower(trim((string) $item['suggestedExclusion'])) : '';
            if ('' === $line) {
                continue;
            }

            $source = isset($item['definingScriptUrl']) ? strtolower(trim((string) $item['definingScriptUrl'])) : '';
            $is_handle_like = false === strpos($line, '/') && !preg_match('/^https?:/i', $line);
            if ($is_handle_like) {
                $token = $this->runtime_js_scan_suggestion_base_token($line);
                foreach ($path_items as $path_item) {
                    $path_line = isset($path_item['line']) ? (string) $path_item['line'] : '';
                    $path_source = isset($path_item['source']) ? (string) $path_item['source'] : '';
                    $path_base = isset($path_item['base']) ? (string) $path_item['base'] : '';
                    if ('' !== $source && '' !== $path_line && false !== strpos($source, $path_line)) {
                        continue 2;
                    }
                    if ('' !== $source && '' !== $path_source && $source === $path_source) {
                        continue 2;
                    }
                    if ('' !== $token && '' !== $path_base && (false !== strpos($path_base, $token) || false !== strpos($token, $path_base))) {
                        continue 2;
                    }
                }
                if ($this->runtime_js_scan_is_inline_extra_handle_suggestion($line) && !empty($path_items)) {
                    continue;
                }
            }

            $key = $this->runtime_js_scan_canonical_suggestion_identity((string) ($item['suggestedExclusion'] ?? ''));
            if ('' === $key || isset($seen_final[$key])) {
                continue;
            }
            $seen_final[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    private function runtime_js_scan_is_generic_token($token)
    {
        $token = strtolower(trim((string) $token));
        if ('' === $token || strlen($token) < 3) {
            return true;
        }

        return in_array($token, array(
            'function',
            'anonymous',
            'jquery',
            'jquery-core',
            'jquery-migrate',
            'jquery.min.js',
            'jquery-migrate.min.js',
            'wp',
            'wp-i18n',
            'wp-hooks',
            'wp-util',
            'wp-api-fetch',
            'api-fetch',
            'api-fetch.min.js',
            'wp-element',
            'react',
            'react-dom',
            'underscore',
            'backbone',
            'dom-ready',
            'wp-dom-ready',
            'js-translations',
            '-js-translations',
            'core',
            'index',
            'indexof',
            'foreach',
            'forEach',
            'hooks',
            'i18n',
            'setlocaledata',
            'setLocaleData',
            'use',
            'then',
            'catch',
            'prototype',
            'plugin',
            'plugins',
            'script',
            'scripts',
            'javascript',
            'dispatch',
            'handle',
            'each',
            'init',
            'ready',
            'main',
            'map',
            'maps',
            'load',
            'callback',
            'min',
            'ver',
            'html',
            'div',
            'body',
            'window',
            'document',
            'event',
            'error',
            'typeerror',
            'undefined',
            'computed',
            'woocommerce',
            'wordpress',
            'functions',
            'params',
            'data',
            'site',
            'frontend',
            'public',
        ), true);
    }

    private function runtime_js_scan_find_scripts_with_symbol_text($symbol, array $scripts)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
            return array();
        }
        $matches = array();
        $seen = array();
        $symbol_regex = preg_quote($symbol, '/');
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $content = $this->runtime_js_scan_script_content($script);
            if ('' === $content || !preg_match('/\b' . $symbol_regex . '\b/', $content)) {
                continue;
            }
            $src = isset($script['src']) ? (string) $script['src'] : '';
            $id = isset($script['id']) ? (string) $script['id'] : '';
            $key = strtolower($src . '|' . $id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $matches[] = array('src' => $src, 'id' => $id);
            if (count($matches) >= 12) {
                break;
            }
        }
        return $matches;
    }

    private function runtime_js_scan_find_scripts_by_global_source_hint($global, array $scripts)
    {
        $global = strtolower(trim((string) $global));
        if ('' === $global || $this->runtime_js_scan_is_generic_token($global)) {
            return array();
        }

        $matches = array();
        $seen = array();
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }

            $src = isset($script['src']) ? (string) $script['src'] : '';
            $id = isset($script['id']) ? (string) $script['id'] : '';
            $handle = isset($script['handle']) ? (string) $script['handle'] : '';
            $haystack = strtolower(html_entity_decode($src . ' ' . $id . ' ' . $handle, ENT_QUOTES, 'UTF-8'));
            if ('' === trim($haystack)) {
                continue;
            }

            $matched = false;
            if (preg_match('/(?:^|[^a-z0-9_$])' . preg_quote($global, '/') . '(?:[^a-z0-9_$]|$)/i', $haystack)) {
                $matched = true;
            } elseif ('' !== $src && '' !== $this->runtime_js_scan_service_fragment_from_source($src, $global)) {
                $matched = true;
            }

            if (!$matched) {
                continue;
            }

            $key = strtolower($src . '|' . $id . '|' . $handle);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $matches[] = array(
                'src'    => $src,
                'id'     => $id,
                'handle' => $handle,
            );
            if (count($matches) >= 12) {
                break;
            }
        }

        return $matches;
    }

    private function runtime_js_scan_dynamic_callback_globals_from_text($text)
    {
        $text = (string) $text;
        if ('' === trim($text)) {
            return array();
        }
        $out = array();
        $identifier = '[A-Za-z_$][A-Za-z0-9_$]*';
        if (preg_match_all('/["\']?([A-Za-z0-9_$.-]*(?:function|callback|handler|method)[A-Za-z0-9_$.-]*)["\']?\s*:\s*["\'](' . $identifier . ')["\']/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = isset($match[2]) ? sanitize_text_field((string) $match[2]) : '';
                if ('' !== $value && !$this->runtime_js_scan_is_generic_token($value)) {
                    $out[$value] = $value;
                }
            }
        }
        if (preg_match_all('/["\'](' . $identifier . ')["\']\s*,\s*["\'](?:event|config|consent|set|js)["\']/i', $text, $call_matches, PREG_SET_ORDER)) {
            foreach ($call_matches as $match) {
                $value = isset($match[1]) ? sanitize_text_field((string) $match[1]) : '';
                if ('' !== $value && !$this->runtime_js_scan_is_generic_token($value)) {
                    $out[$value] = $value;
                }
            }
        }
        return array_values($out);
    }

    private function runtime_js_scan_add_script_identity_suggestions(&$suggestions, &$seen, array $script, $label, $source, $message, $reason, array $exclusions, $confidence = 'review', $global = '')
    {
        $script_src = isset($script['src']) ? (string) $script['src'] : '';
        $script_id = isset($script['id']) ? (string) $script['id'] : '';
        $source_for_display = '' !== $script_src ? $script_src : ('' !== $script_id ? $script_id : $source);
        $fragment = $this->runtime_js_scan_path_fragment_from_source($script_src, 4);
        $has_path_or_service_suggestion = false;
        if ('' !== $fragment) {
            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, $label, $source_for_display, $message, $reason, $exclusions, $confidence);
            $has_path_or_service_suggestion = true;
        } else {
            $service_fragment = $this->runtime_js_scan_service_fragment_from_source($script_src, $global);
            if ('' !== $service_fragment) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $service_fragment, $label . ' service endpoint', $source_for_display, $message, $reason, $exclusions, 'recommended');
                $has_path_or_service_suggestion = true;
            }
        }
        if ('' !== $script_id) {
            $related_id = $this->runtime_js_scan_related_external_id_for_inline_id($script_id);
            if (!$has_path_or_service_suggestion && '' !== $related_id && isset($GLOBALS['ultracache_runtime_js_scan_scripts'])) {
                $related = $this->runtime_js_scan_find_script_by_id((array) $GLOBALS['ultracache_runtime_js_scan_scripts'], $related_id);
                if (!empty($related) && !empty($related['src'])) {
                    $this->runtime_js_scan_add_script_identity_suggestions($suggestions, $seen, $related, $label . ' related external', $source_for_display, $message, $reason, $exclusions, $confidence, $global);
                    return;
                }
            }
            if (!$has_path_or_service_suggestion) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $script_id, $label . ' handle/id', $source_for_display, $message, $reason, $exclusions, $confidence);
            }
            if ('' !== $related_id && isset($GLOBALS['ultracache_runtime_js_scan_scripts'])) {
                $related = $this->runtime_js_scan_find_script_by_id((array) $GLOBALS['ultracache_runtime_js_scan_scripts'], $related_id);
                if (!empty($related) && empty($related['src'])) {
                    $this->runtime_js_scan_add_script_identity_suggestions($suggestions, $seen, $related, $label . ' related external', $source_for_display, $message, $reason, $exclusions, $confidence, $global);
                }
            }
        }
    }

    private function runtime_js_scan_add_dynamic_window_global_suggestions(&$suggestions, &$seen, array $scripts, $source, $message, $detail, array $exclusions)
    {
        $reason = 'A dynamic window[callbackName]() call failed. UltraCache resolved possible callback globals from scanned inline config, sourceURL markers, and stack-frame context. It only shows actual symbols and script ids/paths found in that scanned page.';
        $context_ids = $this->runtime_js_scan_inline_frame_ids_from_text((string) $detail . "
" . (string) $message);
        $context_scripts = array();
        foreach ($context_ids as $inline_id) {
            $script = $this->runtime_js_scan_find_script_by_id($scripts, $inline_id);
            if (!empty($script)) {
                $context_scripts[] = $script;
            }
        }
        foreach ($this->runtime_js_scan_find_scripts_by_source_hint($source, $scripts) as $script) {
            if (!empty($script)) {
                $context_scripts[] = $script;
            }
        }
        if (empty($context_scripts)) {
            $context_scripts = $scripts;
        }

        $globals = array();
        foreach ($context_scripts as $script) {
            $content = $this->runtime_js_scan_script_content($script);
            foreach ($this->runtime_js_scan_dynamic_callback_globals_from_text($content) as $global) {
                $globals[$global] = $global;
            }
        }

        $GLOBALS['ultracache_runtime_js_scan_scripts'] = $scripts;
        foreach ($globals as $global) {
            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $global, 'resolved dynamic window callback global', $source, $message, $reason, $exclusions, 'recommended');
            foreach ($this->runtime_js_scan_find_scripts_with_symbol_text($global, $scripts) as $provider) {
                $this->runtime_js_scan_add_script_identity_suggestions($suggestions, $seen, $provider, 'resolved dynamic callback context script', $source, $message, $reason, $exclusions, 'recommended', $global);
            }
            foreach ($this->runtime_js_scan_find_scripts_by_global_source_hint($global, $scripts) as $provider) {
                $this->runtime_js_scan_add_script_identity_suggestions($suggestions, $seen, $provider, 'resolved dynamic callback source/provider hint', $source, $message, $reason, $exclusions, 'recommended', $global);
            }
        }
        unset($GLOBALS['ultracache_runtime_js_scan_scripts']);
    }

    private function runtime_js_scan_jquery_provider_identity_matches_method($identity, $method)
    {
        $method_token = preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string) $method)));
        if (strlen($method_token) < 3) {
            return false;
        }
        foreach ((array) preg_split('/[\s|]+/', html_entity_decode((string) $identity, ENT_QUOTES, 'UTF-8')) as $part) {
            $token = $this->runtime_js_scan_dependency_identity_token($part);
            if ('' === $token) {
                continue;
            }
            if (in_array($token, array(
                $method_token,
                'jquery' . $method_token,
                $method_token . 'jquery',
                'jqueryplugin' . $method_token,
                'jquery' . $method_token . 'plugin',
            ), true)) {
                return true;
            }
        }
        return false;
    }

    private function runtime_js_scan_find_jquery_plugin_provider_scripts($method, array $scripts)
    {
        $method = trim((string) $method);
        if ('' === $method || empty($scripts)) {
            return array();
        }

        $providers = array();
        $seen = array();
        $method_regex = preg_quote($method, '/');
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $src = isset($script['src']) ? (string) $script['src'] : '';
            $id = isset($script['id']) ? (string) $script['id'] : '';
            $content = $this->runtime_js_scan_script_content($script);
            $matched = $this->runtime_js_scan_jquery_provider_identity_matches_method($src . ' ' . $id, $method);

            if (!$matched && '' !== $content && preg_match('/(?:jQuery|\\$)\\s*\\.\\s*fn\\s*\\.\\s*' . $method_regex . '\\s*=|(?:jQuery|\\$)\\s*\\.\\s*fn\\s*\\[\\s*["\\\']' . $method_regex . '["\\\']\\s*\\]\\s*=/i', $content)) {
                $matched = true;
            } elseif (!$matched && '' !== $content && preg_match('/(?:jQuery|\\$)\\s*\\.\\s*fn\\s*\\.\\s*extend\\s*\\(/i', $content) && preg_match('/["\\\']?' . $method_regex . '["\\\']?\\s*:/i', $content)) {
                $matched = true;
            } elseif (!$matched && '' !== $content && false !== stripos($content, $method) && preg_match('/(?:jQuery|\\$)\\s*\\.\\s*fn\\b|\\.fn\\b/i', $content)) {
                $matched = true;
            }

            if (!$matched) {
                continue;
            }

            $key = strtolower($src . '|' . $id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $providers[] = array('src' => $src, 'id' => $id);
            if (count($providers) >= 8) {
                break;
            }
        }

        return $providers;
    }

    private function runtime_js_scan_find_symbol_provider_scripts($symbol, array $scripts)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol) || empty($scripts)) {
            return array();
        }

        $providers = array();
        $seen = array();
        $symbol_regex = preg_quote($symbol, '/');
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $src = isset($script['src']) ? (string) $script['src'] : '';
            $id = isset($script['id']) ? (string) $script['id'] : '';
            $content = $this->runtime_js_scan_script_content($script);
            if ('' === $content) {
                continue;
            }

            $matched = false;
            if (preg_match('/(?:function|class|var|let|const)\\s+' . $symbol_regex . '\\b/i', $content)) {
                $matched = true;
            } elseif (preg_match('/(?:window|globalThis)\\s*\\.\\s*' . $symbol_regex . '\\b\\s*=/i', $content)) {
                $matched = true;
            } elseif (preg_match('/\\b' . $symbol_regex . '\\s*=\\s*(?:function|\\(|\\{|new\\s+|class\\b)/i', $content)) {
                $matched = true;
            } elseif (false !== stripos($content, $symbol) && false !== stripos((string) $src . ' ' . (string) $id, strtolower($symbol))) {
                $matched = true;
            }

            if (!$matched) {
                continue;
            }

            $key = strtolower($src . '|' . $id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $providers[] = array(
                'src' => $src,
                'id'  => $id,
            );

            if (count($providers) >= 8) {
                break;
            }
        }

        return $providers;
    }

    private function runtime_js_scan_find_scripts_by_source_hint($source, array $scripts)
    {
        $source = $this->runtime_js_scan_sanitize_source((string) $source);
        if ('' === $source || empty($scripts)) {
            return array();
        }

        $source_lc = strtolower(html_entity_decode((string) $source, ENT_QUOTES, 'UTF-8'));
        $source_lc = preg_replace('/(?::\d+){1,2}$/', '', $source_lc);
        $source_base = strtolower($this->runtime_js_scan_basename_from_source($source_lc));
        $source_fragment = strtolower($this->runtime_js_scan_path_fragment_from_source($source_lc, 6));
        $source_path = (string) wp_parse_url($source_lc, PHP_URL_PATH);
        if ('' === $source_path) {
            $source_path = preg_replace('/[?#].*$/', '', $source_lc);
        }
        $source_path = trim(strtolower((string) $source_path), '/');

        $matches = array();
        $seen = array();
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }

            $script_src = isset($script['src']) ? $this->runtime_js_scan_sanitize_source((string) $script['src']) : '';
            $script_id = isset($script['id']) ? sanitize_text_field((string) $script['id']) : '';
            $script_src_lc = strtolower(html_entity_decode((string) $script_src, ENT_QUOTES, 'UTF-8'));
            $script_id_lc = strtolower((string) $script_id);
            $script_base = strtolower($this->runtime_js_scan_basename_from_source($script_src_lc));
            $script_fragment = strtolower($this->runtime_js_scan_path_fragment_from_source($script_src_lc, 6));
            $script_path = (string) wp_parse_url($script_src_lc, PHP_URL_PATH);
            if ('' === $script_path) {
                $script_path = preg_replace('/[?#].*$/', '', $script_src_lc);
            }
            $script_path = trim(strtolower((string) $script_path), '/');

            $matched = false;
            $score = 0;
            if ('' !== $source_fragment && '' !== $script_fragment && (false !== strpos($script_fragment, $source_fragment) || false !== strpos($source_fragment, $script_fragment))) {
                $matched = true;
                $score = 100;
            } elseif ('' !== $source_path && '' !== $script_path && (false !== strpos($script_path, $source_path) || false !== strpos($source_path, $script_path))) {
                $matched = true;
                $score = 90;
            } elseif ('' !== $source_base && '' !== $script_base && $source_base === $script_base) {
                $matched = true;
                $score = $this->runtime_js_scan_is_generic_script_basename($source_base) ? 55 : 75;
            } elseif ('' !== $source_lc && '' !== $script_id_lc && false !== strpos($source_lc, $script_id_lc)) {
                $matched = true;
                $score = 60;
            }

            if (!$matched) {
                continue;
            }

            $key = strtolower($script_src . '|' . $script_id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $script['_ultracache_match_score'] = $score;
            $matches[] = $script;
        }

        usort($matches, static function ($a, $b) {
            $a_score = isset($a['_ultracache_match_score']) ? (int) $a['_ultracache_match_score'] : 0;
            $b_score = isset($b['_ultracache_match_score']) ? (int) $b['_ultracache_match_score'] : 0;
            if ($a_score === $b_score) {
                return 0;
            }
            return ($a_score > $b_score) ? -1 : 1;
        });

        return array_slice($matches, 0, 12);
    }

    private function runtime_js_scan_find_script_by_id(array $scripts, $id)
    {
        $id = trim((string) $id);
        if ('' === $id) {
            return array();
        }
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $script_id = isset($script['id']) ? trim((string) $script['id']) : '';
            $script_handle = isset($script['handle']) ? trim((string) $script['handle']) : '';
            if ($script_id === $id || $script_handle === $id) {
                return $script;
            }
        }
        return array();
    }

    private function runtime_js_scan_add_existing_inline_companion_suggestions(&$suggestions, &$seen, array $scripts, $script_id, $source, $message, $reason, array $exclusions)
    {
        $script_id = trim((string) $script_id);
        if ('' === $script_id || !preg_match('/-js$/i', $script_id)) {
            return;
        }

        foreach (array($script_id . '-before' => 'inline-before config block', $script_id . '-after' => 'inline-after config block') as $companion_id => $label) {
            $companion = $this->runtime_js_scan_find_script_by_id($scripts, $companion_id);
            if (empty($companion)) {
                continue;
            }
            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $companion_id, $label, $source, $message, $reason, $exclusions, 'recommended');
        }
    }

    private function runtime_js_scan_inline_text_uses_symbol($text, $symbol)
    {
        $text = (string) $text;
        $symbol = trim((string) $symbol);
        if ('' === $text || '' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
            return false;
        }

        $symbol_regex = preg_quote($symbol, '/');
        return (bool) preg_match('/(?:^|[^A-Za-z0-9_$])' . $symbol_regex . '\s*(?:\[|\.|\(|;|,|=|\)|\}|$)/', $text);
    }

    private function runtime_js_scan_find_html_adjacency_dependencies($symbol, array $scripts)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol) || count($scripts) < 2) {
            return array();
        }

        $matches = array();
        $seen = array();
        $count = count($scripts);
        for ($index = 1; $index < $count; $index++) {
            $inline = isset($scripts[$index]) && is_array($scripts[$index]) ? $scripts[$index] : array();
            $provider = isset($scripts[$index - 1]) && is_array($scripts[$index - 1]) ? $scripts[$index - 1] : array();
            $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
            $inline_src = isset($inline['src']) ? (string) $inline['src'] : '';
            $inline_text = isset($inline['text']) ? (string) $inline['text'] : '';

            if ('' === $provider_src || '' !== $inline_src || '' === trim($inline_text)) {
                continue;
            }
            if (!$this->runtime_js_scan_inline_text_uses_symbol($inline_text, $symbol)) {
                continue;
            }

            $provider_fragment = $this->runtime_js_scan_path_fragment_from_source($provider_src, 5);
            $provider_base = $this->runtime_js_scan_basename_from_source($provider_src);
            if ('' === $provider_fragment && ('' === $provider_base || $this->runtime_js_scan_is_generic_script_basename($provider_base))) {
                continue;
            }

            $dedupe_key = strtolower($provider_src . '|' . (isset($inline['id']) ? (string) $inline['id'] : '') . '|' . $symbol);
            if (isset($seen[$dedupe_key])) {
                continue;
            }
            $seen[$dedupe_key] = true;
            $matches[] = array(
                'provider' => $provider,
                'inline'   => $inline,
            );

            if (count($matches) >= 6) {
                break;
            }
        }

        return $matches;
    }

    private function runtime_js_scan_add_html_adjacency_suggestions(&$suggestions, &$seen, $symbol, array $scripts, $source, $message, array $exclusions)
    {
        $pairs = $this->runtime_js_scan_find_html_adjacency_dependencies($symbol, $scripts);
        if (empty($pairs)) {
            return false;
        }

        $matched = false;
        foreach ($pairs as $pair) {
            $provider = isset($pair['provider']) && is_array($pair['provider']) ? $pair['provider'] : array();
            $inline = isset($pair['inline']) && is_array($pair['inline']) ? $pair['inline'] : array();
            $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
            $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
            $inline_id = isset($inline['id']) ? (string) $inline['id'] : '';
            $provider_fragment = $this->runtime_js_scan_path_fragment_from_source($provider_src, 5);
            $provider_base = $this->runtime_js_scan_basename_from_source($provider_src);
            $context = trim((string) $provider_id . ('' !== $inline_id ? ' → ' . $inline_id : ''));
            $reason = 'Final HTML adjacency resolver found an external script immediately followed by an inline block that reads the missing global "' . $symbol . '". Keep the external provider script out of Safe Defer/Delay so the inline dependency can execute in order.' . ('' !== $context ? ' Script order: ' . $context . '.' : '');

            if ('' !== $provider_fragment) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_fragment, 'HTML adjacency external provider', $provider_src, $message, $reason, $exclusions, 'confirmed');
                $matched = true;
            }

            if ('' !== $provider_base && !$this->runtime_js_scan_is_generic_script_basename($provider_base)) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_base, 'HTML adjacency provider basename', $provider_src, $message, $reason, $exclusions, 'confirmed');
                $matched = true;
            }
        }

        return $matched;
    }

    private function runtime_js_scan_inline_frame_ids_from_text($text)
    {
        $text = (string) $text;
        if ('' === trim($text)) {
            return array();
        }

        $ids = array();
        if (preg_match_all('/\b([A-Za-z0-9_.-]+-js-(?:before|after|extra|translations))(?::\d+(?::\d+)?)?/i', $text, $matches)) {
            foreach ((array) $matches[1] as $id) {
                $id = sanitize_text_field(substr((string) $id, 0, 160));
                if ('' !== $id) {
                    $ids[strtolower($id)] = $id;
                }
            }
        }

        return array_values($ids);
    }

    private function runtime_js_scan_related_external_id_for_inline_id($inline_id)
    {
        $inline_id = trim((string) $inline_id);
        if ('' === $inline_id) {
            return '';
        }
        if (preg_match('/^(.*-js)-(?:before|after|extra|translations)$/i', $inline_id, $match)) {
            return sanitize_text_field((string) $match[1]);
        }
        return '';
    }

    private function runtime_js_scan_add_inline_stack_frame_suggestions(&$suggestions, &$seen, array $scripts, $text, $message, $reason, array $exclusions, $confidence = 'review')
    {
        foreach ($this->runtime_js_scan_inline_frame_ids_from_text($text) as $inline_id) {
            $script = $this->runtime_js_scan_find_script_by_id($scripts, $inline_id);
            $source = !empty($script['src']) ? (string) $script['src'] : $inline_id;
            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $inline_id, 'inline stack-frame handle/id', $source, $message, $reason, $exclusions, $confidence);

            $related_id = $this->runtime_js_scan_related_external_id_for_inline_id($inline_id);
            if ('' === $related_id) {
                continue;
            }

            $related = $this->runtime_js_scan_find_script_by_id($scripts, $related_id);
            if (!empty($related)) {
                $related_src = isset($related['src']) ? (string) $related['src'] : '';
                $related_fragment = $this->runtime_js_scan_path_fragment_from_source($related_src, 4);
                if ('' !== $related_fragment) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $related_fragment, 'inline stack-frame related external script', $related_src, $message, $reason, $exclusions, $confidence);
                }
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $related_id, 'inline stack-frame related handle/id', $related_src, $message, $reason, $exclusions, $confidence);
            }
        }
    }

    private function runtime_js_scan_add_script_source_resolution_suggestions(&$suggestions, &$seen, array $scripts, $source, $message, $reason, array $exclusions, $label = 'resolved error source script', $confidence = 'review', $include_existing_inline_companions = false)
    {
        foreach ($this->runtime_js_scan_find_scripts_by_source_hint($source, $scripts) as $script) {
            $script_src = isset($script['src']) ? (string) $script['src'] : '';
            $script_id = isset($script['id']) ? (string) $script['id'] : '';
            $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($script_src, 4);
            if ('' !== $fragment) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, $label, $script_src, $message, $reason, $exclusions, $confidence);
            }

            if ('' !== $script_id) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $script_id, $label . ' handle/id', $script_src, $message, $reason, $exclusions, $confidence);
                if ($include_existing_inline_companions) {
                    $this->runtime_js_scan_add_existing_inline_companion_suggestions($suggestions, $seen, $scripts, $script_id, $script_src, $message, 'The scanned page contains an inline companion script next to this external script. Keep existing inline companion ids ordered with their dependent external script.', $exclusions);
                }
            }
        }
    }

    private function runtime_js_scan_find_callback_dependency_context($function_name, array $scripts)
    {
        $function_name = trim((string) $function_name);
        if ('' === $function_name || empty($scripts)) {
            return array('consumers' => array(), 'providers' => array());
        }

        $function_lc = strtolower($function_name);
        $tokens = $this->runtime_js_scan_split_symbol_tokens($function_name);
        $consumers = array();
        $providers = array();

        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }

            $src = isset($script['src']) ? (string) $script['src'] : '';
            $id = isset($script['id']) ? (string) $script['id'] : '';
            $text = isset($script['text']) ? (string) $script['text'] : '';
            $content = $this->runtime_js_scan_script_content($script);
            $provider_text = '' !== $content ? $content : $text;
            $haystack = strtolower($src . ' ' . $id . ' ' . $text . ' ' . substr($content, 0, 24000));

            $is_consumer = false;
            if ('' !== $src) {
                $decoded_src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');
                if (preg_match('/(?:[?&]|&amp;)(?:callback|cb|jsonp)=' . preg_quote($function_name, '/') . '(?:[&#]|$)/i', $decoded_src)) {
                    $is_consumer = true;
                } elseif (false !== strpos(strtolower($decoded_src), 'callback=' . $function_lc)) {
                    $is_consumer = true;
                }
            }

            if ($is_consumer) {
                $consumers[] = $script;
            }

            $is_provider = false;
            if ('' !== $provider_text && preg_match('/(?:function\s+' . preg_quote($function_name, '/') . '\b|window\s*\.\s*' . preg_quote($function_name, '/') . '\b|' . preg_quote($function_name, '/') . '\s*=)/i', $provider_text)) {
                $is_provider = true;
            }

            if (!$is_provider && false !== strpos($haystack, $function_lc)) {
                $is_provider = true;
            }

            if (!$is_provider) {
                foreach ($tokens as $token) {
                    if ($this->runtime_js_scan_is_generic_token($token)) {
                        continue;
                    }
                    if (false !== strpos($haystack, strtolower($token))) {
                        $is_provider = true;
                        break;
                    }
                }
            }

            if ($is_provider && !$is_consumer) {
                $providers[] = $script;
            }
        }

        return array(
            'consumers' => array_slice($consumers, 0, 8),
            'providers' => array_slice($providers, 0, 12),
        );
    }

    private function runtime_js_scan_add_function_dependency_suggestions(&$suggestions, &$seen, $function_name, $source, $message, $detail, array $exclusions, array $scripts = array())
    {
        $function_name = trim((string) $function_name);
        if ('' === $function_name || $this->runtime_js_scan_is_generic_token($function_name)) {
            return;
        }

        $context = $this->runtime_js_scan_find_callback_dependency_context($function_name, $scripts);
        $has_callback_consumer = !empty($context['consumers']);
        $reason = $has_callback_consumer
            ? 'A browser runtime error says a global callback/function was called before it existed, and Runtime Scan found a script URL using that callback name. Keep the callback provider before the callback consumer, or exclude the smallest provider/consumer script fragments and scan again.'
            : 'A runtime error says a callback/function was called before it was available. Suggestions are derived from the missing function name and stack/source URLs; add the smallest matching exclusions and scan again.';
        $source_base = $this->runtime_js_scan_basename_from_source($source);
        $stack_text = (string) $source . "
" . (string) $detail . "
" . (string) $message;

        if ('' !== $source_base && preg_match('/\.js$/i', $source_base) && !$this->runtime_js_scan_is_generic_script_basename($source_base)) {
            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'runtime function error source', $source, $message, $reason, $exclusions, 'recommended');
        }

        // Do not append raw function/global names as exclusions. Only exact provider/consumer scripts or resolved URL fragments are actionable.

        foreach ((array) ($context['providers'] ?? array()) as $provider) {
            $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
            $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
            $provider_fragment = $this->runtime_js_scan_path_fragment_from_source($provider_src, 4);
            if ('' !== $provider_fragment) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_fragment, 'callback provider script', $provider_src, $message, $reason, $exclusions, 'recommended');
                continue;
            }
            $provider_base = $this->runtime_js_scan_basename_from_source($provider_src);
            if ('' !== $provider_base && !$this->runtime_js_scan_is_generic_script_basename($provider_base)) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_base, 'callback provider script basename', $provider_src, $message, $reason, $exclusions, 'recommended');
                continue;
            }
            if ('' !== $provider_id) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_id, 'callback provider handle/id', $provider_src, $message, $reason, $exclusions, 'recommended');
            }
        }

        foreach ((array) ($context['consumers'] ?? array()) as $consumer) {
            $consumer_src = isset($consumer['src']) ? (string) $consumer['src'] : '';
            $consumer_fragment = $this->runtime_js_scan_path_fragment_from_source($consumer_src, 4);
            if ('' !== $consumer_fragment) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $consumer_fragment, 'callback consumer script', $consumer_src, $message, $reason, $exclusions, 'recommended');
            }
            foreach ($this->runtime_js_scan_url_fragments_from_text($consumer_src) as $consumer_url_fragment) {
                if ('' === $consumer_url_fragment || $this->runtime_js_scan_is_generic_token($consumer_url_fragment)) {
                    continue;
                }
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $consumer_url_fragment, 'callback consumer URL fragment', $consumer_src, $message, $reason, $exclusions, 'recommended');
            }
            if (false !== stripos($consumer_src, 'callback=' . $function_name)) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'callback=' . $function_name, 'callback consumer query arg', $consumer_src, $message, $reason, $exclusions, 'recommended');
            }
        }

        foreach ($this->runtime_js_scan_url_fragments_from_text($stack_text) as $fragment) {
            $fragment = trim((string) $fragment);
            if ('' === $fragment || $this->runtime_js_scan_is_generic_token($fragment)) {
                continue;
            }
            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, 'runtime stack URL fragment', $source, $message, $reason, $exclusions, 'recommended');
        }
    }

    private function runtime_js_scan_add_jquery_plugin_dependency_suggestions(&$suggestions, &$seen, $method, $source, $message, $detail, array $exclusions, array $scripts = array())
    {
        $method = trim((string) $method);
        if ('' === $method) {
            return;
        }

        foreach ($this->runtime_js_scan_find_jquery_plugin_provider_scripts($method, $scripts) as $provider) {
            $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
            $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
            $provider_fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($provider_src, 5);
            if ('' !== $provider_fragment) {
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $provider_fragment,
                    'jQuery plugin provider script',
                    $provider_src,
                    $message,
                    'Runtime Scan resolved the exact script that registers jQuery.fn.' . sanitize_text_field($method) . '. Keep this provider available before the direct consumer.',
                    $exclusions,
                    'recommended'
                );
                continue;
            }
            if ('' !== $provider_id) {
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $provider_id,
                    'jQuery plugin provider handle/id',
                    $provider_src,
                    $message,
                    'Runtime Scan resolved the script handle that registers jQuery.fn.' . sanitize_text_field($method) . '.',
                    $exclusions,
                    'recommended'
                );
            }
        }

        $consumer_source = $this->runtime_js_scan_clean_console_candidate((string) $source);
        if ('' === $consumer_source || $this->runtime_js_scan_is_generic_script_basename($this->runtime_js_scan_basename_from_source($consumer_source))) {
            $consumer_source = $this->runtime_js_scan_source_from_text((string) $detail . "\n" . (string) $message);
        }
        $matched = $this->runtime_js_scan_find_scripts_by_source_hint($consumer_source, $scripts);
        if (!empty($matched) && !empty($matched[0]['src'])) {
            $consumer_source = (string) $matched[0]['src'];
        }
        $consumer_fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($consumer_source, 5);
        if ('' !== $consumer_fragment) {
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $consumer_fragment,
                'jQuery plugin direct consumer',
                $consumer_source,
                $message,
                'This is the direct stack-frame script that called .' . sanitize_text_field($method) . '() before its jQuery plugin provider was registered. Keep it in the same execution strategy as the provider.',
                $exclusions,
                'recommended'
            );
        }
    }

    private function runtime_js_scan_add_known_dependency_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions, array $scripts = array())
    {
        $text = strtolower((string) $message . ' ' . (string) $source . ' ' . (string) $detail);
        $matched = false;

        if (false !== strpos($text, 'wp is not defined') || false !== strpos($text, 'wp.')) {
            $matched = true;
            $reason = 'Browser runtime error points to a WordPress core dependency that executed before its provider. If the recommended dependency paths are already listed, this indicates a script execution-order issue rather than a missing exclusion.';
            $this->runtime_js_scan_add_direct_source_review_suggestion($suggestions, $seen, $source, $message, $reason, $exclusions, 'wp-dependent direct source');
            $this->runtime_js_scan_add_script_source_resolution_suggestions($suggestions, $seen, $scripts, $source, $message, $reason, $exclusions, 'wp-dependent resolved source', 'recommended', true);
            $this->runtime_js_scan_add_inline_stack_frame_suggestions($suggestions, $seen, $scripts, (string) $detail . "\n" . (string) $message, $message, $reason, $exclusions, 'recommended');
        }

        if (false !== strpos($text, 'react is not defined') || false !== strpos($text, "react' is not defined") || false !== strpos($text, "can't find variable: react") || false !== strpos($text, 'reactdom is not defined')) {
            $matched = true;
            $reason = 'Browser runtime error points to a React dependency that executed before its provider. Review the exact source shown by the scanner; do not add broad framework handles blindly.';
            $this->runtime_js_scan_add_direct_source_review_suggestion($suggestions, $seen, $source, $message, $reason, $exclusions, 'React dependent direct source');
            $this->runtime_js_scan_add_script_source_resolution_suggestions($suggestions, $seen, $scripts, $source, $message, $reason, $exclusions, 'React dependent resolved source', 'recommended', true);
            $this->runtime_js_scan_add_inline_stack_frame_suggestions($suggestions, $seen, $scripts, (string) $detail . "\n" . (string) $message, $message, $reason, $exclusions, 'recommended');
        }

        return $matched;
    }

    private function runtime_js_scan_error_theme_lookup_tokens($message, $detail)
    {
        $text = (string) $message . "\n" . (string) $detail;
        $tokens = array();
        $push = function ($token) use (&$tokens) {
            $token = trim((string) $token);
            $token = trim($token, " \t\n\r\0\x0B.\\/[](){}'\"");
            if ('' === $token) {
                return;
            }
            if (false !== strpos($token, '.')) {
                $parts = array_values(array_filter(array_map('trim', explode('.', $token))));
                foreach ($parts as $part) {
                    if ('' !== $part && !$this->runtime_js_scan_is_generic_token($part)) {
                        $tokens[$part] = $part;
                    }
                }
            }
            if (!$this->runtime_js_scan_is_generic_token($token)) {
                $tokens[$token] = $token;
            }
        };

        if (preg_match_all('/(?:ReferenceError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]*)\s+is\s+not\s+defined/i', $text, $matches)) {
            foreach ((array) $matches[1] as $match) {
                $push($match);
            }
        }

        if (preg_match_all('/(?:InvalidValueError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]{2,})\s+is\s+not\s+a\s+function/i', $text, $matches)) {
            foreach ((array) $matches[1] as $match) {
                $push($match);
            }
        }

        if (preg_match_all('/Cannot\s+read\s+properties\s+of\s+undefined\s+\(reading\s+[\'\"]([^\'\"]+)[\'\"]\)/i', $text, $matches)) {
            foreach ((array) $matches[1] as $match) {
                $push($match);
            }
        }

        if (preg_match_all('/window\s*\[\s*[\'\"]?([A-Za-z_$][A-Za-z0-9_$.-]{2,})[\'\"]?\s*\]\s+is\s+not\s+a\s+function/i', $text, $matches)) {
            foreach ((array) $matches[1] as $match) {
                $push($match);
            }
        }

        return array_slice(array_values($tokens), 0, 8);
    }

    private function runtime_js_scan_theme_file_uses_token($content, $token)
    {
        $content = (string) $content;
        $token = trim((string) $token);
        if ('' === $content || '' === $token || $this->runtime_js_scan_is_generic_token($token)) {
            return false;
        }

        $quoted = preg_quote($token, '/');
        if (preg_match('/(?:function|class|var|let|const)\s+' . $quoted . '\b/i', $content)) {
            return true;
        }
        if (preg_match('/(?:window|globalThis)\s*\.\s*' . $quoted . '\s*=/i', $content)) {
            return true;
        }
        if (preg_match('/[\'\"]' . $quoted . '[\'\"]\s*:/i', $content)) {
            return true;
        }
        if (preg_match('/\b' . $quoted . '\b/i', $content)) {
            return true;
        }

        return false;
    }

    private function runtime_js_scan_add_theme_stage_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions)
    {
        $tokens = $this->runtime_js_scan_error_theme_lookup_tokens($message, $detail);
        if (empty($tokens)) {
            return false;
        }

        $matched = false;
        foreach ($this->runtime_js_scan_theme_stage_roots() as $root) {
            $root_dir = isset($root['dir']) ? (string) $root['dir'] : '';
            $root_uri = isset($root['uri']) ? (string) $root['uri'] : '';
            $stage = isset($root['stage']) ? (string) $root['stage'] : 'theme';
            if ('' === $root_dir || '' === $root_uri) {
                continue;
            }

            foreach ($this->runtime_js_scan_theme_stage_files($root_dir) as $file) {
                $content = function_exists('ultracache_guarded_asset_file_get_contents') ? ultracache_guarded_asset_file_get_contents($file, 'js', 'runtime_js_theme_stage_scan', true) : false;
                if (!is_string($content) || '' === $content) {
                    continue;
                }

                $matched_tokens = array();
                foreach ($tokens as $token) {
                    if ($this->runtime_js_scan_theme_file_uses_token($content, $token)) {
                        $matched_tokens[] = $token;
                    }
                }
                if (empty($matched_tokens)) {
                    continue;
                }

                $relative = $this->runtime_js_scan_theme_stage_relative_path($file, $root_dir);
                if ('' === $relative) {
                    continue;
                }
                $url = esc_url_raw(trailingslashit($root_uri) . ltrim($relative, '/'));
                $fragment = $this->runtime_js_scan_path_fragment_from_source($url, 5);
                if ('' === $fragment) {
                    continue;
                }

                $matched = true;
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $fragment,
                    'Theme Scan Stage ' . $stage,
                    $url,
                    $message,
                    'Theme code search found unresolved token(s) ' . implode(', ', array_map('sanitize_text_field', $matched_tokens)) . ' in this exact active theme JS file.',
                    $exclusions,
                    'recommended'
                );

                if (count($matched_tokens) > 0 && count($suggestions) >= 80) {
                    return true;
                }
            }

            if ($matched) {
                return true;
            }
        }

        return $matched;
    }

    private function runtime_js_scan_add_plugin_stage_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions)
    {
        $tokens = $this->runtime_js_scan_error_theme_lookup_tokens($message, $detail);
        if (empty($tokens)) {
            return false;
        }

        $matched = false;
        foreach ($this->runtime_js_scan_plugin_stage_roots($source, $message, $detail) as $root) {
            $root_dir = isset($root['dir']) ? (string) $root['dir'] : '';
            $root_uri = isset($root['uri']) ? (string) $root['uri'] : '';
            $stage = isset($root['stage']) ? (string) $root['stage'] : 'plugin';
            $max_files = isset($root['max_files']) ? (int) $root['max_files'] : 60;
            $max_depth = isset($root['max_depth']) ? (int) $root['max_depth'] : 5;
            if ('' === $root_dir || '' === $root_uri) {
                continue;
            }

            foreach ($this->runtime_js_scan_plugin_stage_files($root_dir, $max_files, $max_depth) as $file) {
                $content = function_exists('ultracache_guarded_asset_file_get_contents') ? ultracache_guarded_asset_file_get_contents($file, 'js', 'runtime_js_plugin_stage_scan', true) : false;
                if (!is_string($content) || '' === $content) {
                    continue;
                }

                $matched_tokens = array();
                foreach ($tokens as $token) {
                    if ($this->runtime_js_scan_theme_file_uses_token($content, $token)) {
                        $matched_tokens[] = $token;
                    }
                }
                if (empty($matched_tokens)) {
                    continue;
                }

                $relative = $this->runtime_js_scan_theme_stage_relative_path($file, $root_dir);
                if ('' === $relative) {
                    continue;
                }
                $url = esc_url_raw(trailingslashit($root_uri) . ltrim($relative, '/'));
                $fragment = $this->runtime_js_scan_path_fragment_from_source($url, 5);
                if ('' === $fragment) {
                    continue;
                }

                $matched = true;
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $fragment,
                    'Plugin Scan Stage ' . $stage,
                    $url,
                    $message,
                    'Plugin code search found unresolved token(s) ' . implode(', ', array_map('sanitize_text_field', $matched_tokens)) . ' in this exact active plugin JS file.',
                    $exclusions,
                    'recommended'
                );

                if (count($suggestions) >= 80) {
                    return true;
                }
            }

            if ($matched) {
                return true;
            }
        }

        return $matched;
    }

    private function runtime_js_scan_is_explicit_runtime_error($message, $detail = '')
    {
        $text = trim((string) $message . "\n" . (string) $detail);
        if ('' === $text) {
            return false;
        }

        return (bool) preg_match(
            '/(?:Uncaught\s+)?(?:ReferenceError|TypeError|SyntaxError|RangeError|EvalError|URIError|Error):|jQuery\.Deferred exception|\bis not defined\b|\bis not a function\b|Cannot read properties|window\[[^\]]+\]\s+is\s+not\s+a\s+function/i',
            $text
        );
    }

    private function runtime_js_scan_is_ignorable_console_error($message, $detail = '', $source = '')
    {
        $text = strtolower(trim((string) $message . ' ' . (string) $detail . ' ' . (string) $source));
        if ('' === $text) {
            return true;
        }
        if (preg_match('/^\s*\d+\s*$/', $text)) {
            return true;
        }
        if (false !== strpos($text, 'jqmigrate: migrate is installed') && false === strpos($text, 'uncaught') && false === strpos($text, 'typeerror') && false === strpos($text, 'referenceerror') && false === strpos($text, 'syntaxerror') && false === strpos($text, 'cannot read properties')) {
            return true;
        }
        if (false !== strpos($text, 'google maps javascript api warning') || false !== strpos($text, 'noapikeys')) {
            return true;
        }
        if (preg_match('/^\s*understand this (?:error|warning)\s*$/i', $text)) {
            return true;
        }
        if (false !== strpos($text, ' opt-in') && false === strpos($text, 'error') && false === strpos($text, 'uncaught')) {
            return true;
        }
        return false;
    }

    private function runtime_js_scan_extract_missing_jquery_methods_from_error($message, $detail = '')
    {
        $text = (string) $message . "\n" . (string) $detail;
        $methods = array();
        $push = static function ($method) use (&$methods) {
            $method = trim((string) $method);
            if ('' === $method || !preg_match('/^[A-Za-z_$][A-Za-z0-9_$-]{1,80}$/', $method)) {
                return;
            }
            $methods[strtolower($method)] = sanitize_text_field($method);
        };

        if (preg_match_all('/(?:TypeError:\s*)?(?:[A-Za-z_$][A-Za-z0-9_$]*(?:\[[^\]]+\])?|\$\([^\n]*?\)|jQuery\([^\n]*?\))\s*\.\s*([A-Za-z_$][A-Za-z0-9_$-]*)\s+is\s+not\s+a\s+function/i', $text, $matches)) {
            foreach ((array) $matches[1] as $method) {
                $push($method);
            }
        }
        if (preg_match_all('/(?:TypeError:\s*)?[A-Za-z_$][A-Za-z0-9_$]*\s*\[\s*["\']([A-Za-z_$][A-Za-z0-9_$-]*)["\']\s*\]\s+is\s+not\s+a\s+function/i', $text, $matches)) {
            foreach ((array) $matches[1] as $method) {
                $push($method);
            }
        }

        return array_values($methods);
    }

    private function runtime_js_scan_extract_missing_symbols_from_error($message, $detail = '')
    {
        $text = (string) $message . "\n" . (string) $detail;
        $symbols = array();
        $push = function ($symbol) use (&$symbols) {
            $symbol = trim((string) $symbol);
            $symbol = preg_replace('/[^A-Za-z0-9_$.-]/', '', $symbol);
            if ('' === $symbol) {
                return;
            }
            if ($this->runtime_js_scan_is_generic_token($symbol) && !$this->runtime_js_scan_is_explicit_missing_global($symbol)) {
                return;
            }
            $symbols[strtolower($symbol)] = sanitize_text_field(substr($symbol, 0, 120));
        };

        if (preg_match_all('/(?:ReferenceError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]*)\s+is\s+not\s+defined/i', $text, $matches)) {
            foreach ((array) $matches[1] as $symbol) {
                $push($symbol);
            }
        }
        if (preg_match_all('/(?:TypeError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]*)\s+is\s+not\s+a\s+function/i', $text, $matches)) {
            foreach ((array) $matches[1] as $symbol) {
                $push($symbol);
            }
        }
        if (preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$.-]{2,})\s*\.\s*[A-Za-z_$][A-Za-z0-9_$-]*\s+is\s+not\s+a\s+function/i', $text, $matches)) {
            foreach ((array) $matches[1] as $symbol) {
                $push($symbol);
            }
        }

        return array_values($symbols);
    }

    private function runtime_js_scan_file_defines_symbol($content, $symbol)
    {
        $content = (string) $content;
        $symbol = trim((string) $symbol);
        if ('' === $content || '' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
            return false;
        }
        $quoted = preg_quote($symbol, '/');
        $patterns = array(
            '/(?:^|[^A-Za-z0-9_$])function\s+' . $quoted . '\s*\(/',
            '/(?:^|[^A-Za-z0-9_$])(?:var|let|const)\s+' . $quoted . '\s*=/',
            '/(?:^|[^A-Za-z0-9_$])' . $quoted . '\s*=\s*function\b/',
            '/(?:window|globalThis)\s*\.\s*' . $quoted . '\s*=/',
            '/(?:window|globalThis)\s*\[\s*["\']' . $quoted . '["\']\s*\]\s*=/',
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        return false;
    }

    private function runtime_js_scan_startup_navigation_trigger_scripts(array $scripts)
    {
        $synthetic_change = array();
        $direct_navigation = array();
        $path_candidates = array();
        $seen = array();

        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $src = isset($script['src']) ? (string) $script['src'] : '';
            if ('' === $src || $this->runtime_js_scan_is_ultracache_runtime_helper_source($src)) {
                continue;
            }
            if (empty($this->runtime_js_scan_owner_from_script_source($src))) {
                continue;
            }
            $content = $this->runtime_js_scan_script_content($script);
            $content_lc = strtolower($content);
            $src_lc = strtolower($src);
            $id_lc = strtolower((string) ($script['id'] ?? ''));
            $handle_lc = strtolower((string) ($script['handle'] ?? ''));
            $identity_lc = $src_lc . ' ' . $id_lc . ' ' . $handle_lc;
            $is_delayed = !empty($script['delayed']);
            $is_theme_asset = function_exists('ultracache_public_path_contains_any')
                && function_exists('ultracache_themes_public_paths')
                && ultracache_public_path_contains_any($identity_lc, ultracache_themes_public_paths());
            $has_woocommerce_order_target = (false !== strpos($content_lc, 'woocommerce-ordering') || false !== strpos($content_lc, 'orderby') || false !== strpos($identity_lc, 'woocommerce') || false !== strpos($identity_lc, 'orderby'));
            $fires_synthetic_change = (bool) preg_match('/(?:\.\s*change\s*\(\s*\)|\.\s*trigger\s*\(\s*["\']change["\']\s*\))/i', $content);
            $sets_location = (bool) preg_match('/(?:window\s*\.\s*)?location\s*(?:=|\.\s*(?:href|assign|replace)\s*(?:=|\())/i', $content);
            $mentions_filter_redirect = (false !== strpos($content_lc, 'woof_submit_link') || false !== strpos($content_lc, 'woof_current_values') || false !== strpos($content_lc, 'swoof='));

            $key = strtolower($src . '|' . (string) ($script['id'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }

            if ($has_woocommerce_order_target && $fires_synthetic_change) {
                $seen[$key] = true;
                $script['_ultracache_navigation_match'] = 'synthetic-change';
                $script['_ultracache_navigation_score'] = 120 + ($is_delayed ? 20 : 0) + ($is_theme_asset ? 10 : 0);
                $synthetic_change[] = $script;
                continue;
            }

            if ($sets_location && ($mentions_filter_redirect || $has_woocommerce_order_target)) {
                $seen[$key] = true;
                $script['_ultracache_navigation_match'] = 'direct-navigation';
                $script['_ultracache_navigation_score'] = 80 + ($is_delayed ? 10 : 0);
                $direct_navigation[] = $script;
                continue;
            }

            $path_score = 0;
            if ($is_theme_asset) {
                $path_score += 20;
            }
            if ($is_delayed) {
                $path_score += 20;
            }
            if (false !== strpos($identity_lc, '/woocommerce/')) {
                $path_score += 25;
            }
            if (false !== strpos($identity_lc, 'products')) {
                $path_score += 15;
            }
            if (preg_match('#/woocommerce/main(?:\.min)?\.js#i', $identity_lc)) {
                $path_score += 45;
            } elseif (preg_match('#/woocommerce/shop-select(?:\.min)?\.js#i', $identity_lc)) {
                $path_score += 30;
            } elseif (preg_match('#/woocommerce/shop(?:\.min)?\.js#i', $identity_lc)) {
                $path_score += 20;
            }
            if (false !== strpos($identity_lc, 'woocommerce-products-filter') || false !== strpos($identity_lc, 'woof')) {
                $path_score += 12;
            }

            if ($path_score >= 60) {
                $seen[$key] = true;
                $script['_ultracache_navigation_match'] = 'path-ranked-navigation-candidate';
                $script['_ultracache_navigation_score'] = $path_score;
                $path_candidates[] = $script;
            }
        }

        $sort_by_score = static function ($a, $b) {
            $a_score = isset($a['_ultracache_navigation_score']) ? (int) $a['_ultracache_navigation_score'] : 0;
            $b_score = isset($b['_ultracache_navigation_score']) ? (int) $b['_ultracache_navigation_score'] : 0;
            if ($a_score === $b_score) {
                return 0;
            }
            return ($a_score > $b_score) ? -1 : 1;
        };

        usort($synthetic_change, $sort_by_score);
        usort($direct_navigation, $sort_by_score);
        usort($path_candidates, $sort_by_score);

        if (!empty($synthetic_change)) {
            return array_slice($synthetic_change, 0, 4);
        }
        if (!empty($path_candidates)) {
            return array_slice($path_candidates, 0, 4);
        }
        return array_slice($direct_navigation, 0, 4);
    }

    private function runtime_js_scan_add_interrupted_navigation_suggestions(&$suggestions, &$seen, array $error, array $scripts, array $exclusions)
    {
        $kind = isset($error['kind']) ? strtolower((string) $error['kind']) : '';
        if ('scan-navigation-before-collector' !== $kind) {
            return false;
        }

        $message = isset($error['message']) ? sanitize_text_field((string) $error['message']) : '';
        $source = isset($error['source']) ? $this->runtime_js_scan_sanitize_source((string) $error['source']) : '';
        if (empty($scripts) && '' !== $source) {
            $scripts = $this->runtime_js_scan_fetch_script_inventory_for_url($source);
        }

        $matches = $this->runtime_js_scan_startup_navigation_trigger_scripts($scripts);
        $added = false;
        foreach ($matches as $script) {
            $script_src = isset($script['src']) ? (string) $script['src'] : '';
            $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($script_src, 5);
            if ('' === $fragment) {
                continue;
            }

            $match_type = isset($script['_ultracache_navigation_match']) ? (string) $script['_ultracache_navigation_match'] : '';
            $reason = 'Browser Scanner saw the diagnostic popup navigate away before the page collector could report. UltraCache found this local script in the scanned page and it contains startup navigation/change behavior, so this is an exclusion-first candidate: add it to "Do Not Defer or Delay" and rescan.';
            if ('synthetic-change' === $match_type) {
                $reason = 'Browser Scanner saw the diagnostic popup navigate away before the page collector could report. UltraCache found this local script firing a startup change event on a WooCommerce/orderby control. If another plugin listens to that change and redirects, delaying this script can create a reload loop. Add it to "Do Not Defer or Delay" and rescan.';
            } elseif ('direct-navigation' === $match_type) {
                $reason = 'Browser Scanner saw the diagnostic popup navigate away before the page collector could report. UltraCache found this local script setting browser location during filter/order startup. Add it to "Do Not Defer or Delay" and rescan.';
            } elseif ('path-ranked-navigation-candidate' === $match_type) {
                $reason = 'Browser Scanner saw the diagnostic popup navigate away before the page collector could report. UltraCache could not rely on a console error, so it ranked local delayed WooCommerce/filter startup scripts from the scanned page. This script is an exclusion-first candidate; add it to "Do Not Defer or Delay" and rescan.';
            }

            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $fragment,
                'scan navigation interrupted before collector',
                $script_src,
                $message,
                $reason,
                $exclusions,
                'recommended',
                'exclusion'
            );
            $added = true;
        }

        return $added;
    }

    private function runtime_js_scan_add_navigation_loop_suggestions(&$suggestions, &$seen, array $error, array $scripts, array $exclusions)
    {
        $kind = isset($error['kind']) ? strtolower((string) $error['kind']) : '';
        if ('same-url-navigation-loop' !== $kind) {
            return false;
        }

        $message = isset($error['message']) ? sanitize_text_field((string) $error['message']) : '';
        $source = isset($error['source']) ? $this->runtime_js_scan_sanitize_source((string) $error['source']) : '';
        $detail = isset($error['detail']) ? sanitize_textarea_field((string) $error['detail']) : '';
        if ('' === $source) {
            $source = $this->runtime_js_scan_source_from_text($message . ' ' . $detail);
        }

        $direct_sources = $this->runtime_js_scan_collect_direct_stack_sources($source, $message, $detail, $scripts);
        if (empty($direct_sources) && '' !== $source) {
            $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($source, 5);
            if ('' !== $fragment) {
                $direct_sources[] = array(
                    'source'   => $source,
                    'fragment' => $fragment,
                );
            }
        }
        if (empty($scripts) && '' !== $detail) {
            $decoded_detail = json_decode($detail, true);
            $loop_url = is_array($decoded_detail) && !empty($decoded_detail['normalizedUrl']) ? $this->runtime_js_scan_sanitize_display_url((string) $decoded_detail['normalizedUrl']) : '';
            if ('' !== $loop_url) {
                $scripts = $this->runtime_js_scan_fetch_script_inventory_for_url($loop_url);
            }
        }

        $added = false;
        $direct_fragments = array();
        $count = 0;
        foreach ($direct_sources as $direct) {
            $fragment = isset($direct['fragment']) ? (string) $direct['fragment'] : '';
            $direct_source = isset($direct['source']) ? (string) $direct['source'] : '';
            if ('' === $fragment) {
                continue;
            }
            $direct_fragments[strtolower($fragment)] = true;
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $fragment,
                'same-url reload loop trigger',
                $direct_source,
                $message,
                'Runtime Scan detected repeated full-page navigation back to the same URL. The last synthetic startup event before unload points to this script, so this is an exclusion-first fix: add it to "Do Not Defer or Delay" and rescan.',
                $exclusions,
                'recommended',
                'exclusion'
            );
            $added = true;
            $count++;
            if ($count >= 3) {
                break;
            }
        }

        $trigger_count = 0;
        foreach ($this->runtime_js_scan_startup_navigation_trigger_scripts($scripts) as $script) {
            $script_src = isset($script['src']) ? (string) $script['src'] : '';
            $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($script_src, 5);
            if ('' === $fragment || isset($direct_fragments[strtolower($fragment)])) {
                continue;
            }

            $match_type = isset($script['_ultracache_navigation_match']) ? (string) $script['_ultracache_navigation_match'] : '';
            $reason = 'Runtime Scan detected a same-URL reload loop. The final redirect/listener script may already be excluded, so UltraCache also scanned the printed plugin/theme scripts for upstream startup event triggers. This delayed local script is an exclusion-first candidate; add it to "Do Not Defer or Delay" and rescan.';
            if ('synthetic-change' === $match_type) {
                $reason = 'Runtime Scan detected a same-URL reload loop. The final redirect/listener script may already be excluded, so UltraCache also scanned printed plugin/theme scripts and found this delayed local script firing a startup change event on a WooCommerce/orderby control. That synthetic event can wake another plugin redirect listener. Add it to "Do Not Defer or Delay" and rescan.';
            } elseif ('path-ranked-navigation-candidate' === $match_type) {
                $reason = 'Runtime Scan detected a same-URL reload loop. The final redirect/listener script may already be excluded, so UltraCache also ranked delayed local WooCommerce/filter startup scripts from the printed page. This script is an upstream trigger candidate; add it to "Do Not Defer or Delay" and rescan.';
            }

            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $fragment,
                'same-url upstream startup event trigger',
                $script_src,
                $message,
                $reason,
                $exclusions,
                'recommended',
                'exclusion'
            );
            $added = true;
            $trigger_count++;
            if ($trigger_count >= 3) {
                break;
            }
        }

        return $added;
    }

    private function build_runtime_js_scan_suggestions(array $errors, array $scripts = array())
    {
        $exclusions = $this->get_runtime_js_scan_current_exclusions();
        $suggestions = array();
        $seen = array();

        $explicit_dependency_text = '';
        foreach ($errors as $error_for_dependency_pass) {
            if (!is_array($error_for_dependency_pass)) {
                continue;
            }
            $explicit_dependency_text .= "\n" . (string) ($error_for_dependency_pass['message'] ?? '');
            $explicit_dependency_text .= "\n" . (string) ($error_for_dependency_pass['detail'] ?? '');
        }
        if ('' !== trim($explicit_dependency_text)) {
            $this->runtime_js_scan_add_explicit_wp_dependency_suggestions_from_text(
                $suggestions,
                $seen,
                $explicit_dependency_text,
                '',
                $exclusions
            );
        }

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $message = isset($error['message']) ? sanitize_text_field((string) $error['message']) : '';
            $source = isset($error['source']) ? $this->runtime_js_scan_sanitize_source((string) $error['source']) : '';
            $detail = isset($error['detail']) ? sanitize_textarea_field((string) $error['detail']) : '';
            if ('' === $source) {
                $source = $this->runtime_js_scan_source_from_text($message . ' ' . $detail);
            }

            $is_explicit_runtime_error = $this->runtime_js_scan_is_explicit_runtime_error($message, $detail);
            if (!$is_explicit_runtime_error && $this->runtime_js_scan_is_ignorable_console_error($message, $detail, $source)) {
                continue;
            }

            if ($this->runtime_js_scan_add_interrupted_navigation_suggestions($suggestions, $seen, $error, $scripts, $exclusions)) {
                continue;
            }

            if ($this->runtime_js_scan_add_navigation_loop_suggestions($suggestions, $seen, $error, $scripts, $exclusions)) {
                continue;
            }

            $direct_sources = $this->runtime_js_scan_collect_direct_stack_sources($source, $message, $detail, $scripts);
            $direct_owners = !empty($direct_sources) ? $this->runtime_js_scan_unique_direct_source_owners($direct_sources) : array();
            $symbols = $this->runtime_js_scan_extract_missing_symbols_from_error($message, $detail);

            if ($this->runtime_js_scan_add_duplicate_execution_warning($suggestions, $seen, $source, $message, $detail, $exclusions)) {
                continue;
            }

            if ($this->runtime_js_scan_add_jquery_migrate_dependency_suggestions($suggestions, $seen, $source, $message, $detail, $scripts, $exclusions)) {
                continue;
            }

            $explicit_wp_provider_added = $this->runtime_js_scan_add_explicit_wp_dependency_suggestions_from_text($suggestions, $seen, $message, $detail, $exclusions);

            // Resolve missing jQuery prototype methods before treating the full
            // expression (for example counter.appear) as a missing global.
            $jquery_plugin_provider_added = false;
            foreach ($this->runtime_js_scan_extract_missing_jquery_methods_from_error($message, $detail) as $method) {
                if (empty($this->runtime_js_scan_find_jquery_plugin_provider_scripts($method, $scripts))) {
                    continue;
                }
                $this->runtime_js_scan_add_jquery_plugin_dependency_suggestions(
                    $suggestions,
                    $seen,
                    $method,
                    $source,
                    $message,
                    $detail,
                    $exclusions,
                    $scripts
                );
                $jquery_plugin_provider_added = true;
            }
            if ($jquery_plugin_provider_added) {
                continue;
            }

            $provider_added = false;
            foreach ($symbols as $symbol) {
                if ($this->runtime_js_scan_add_missing_global_provider_suggestions($suggestions, $seen, $symbol, $direct_sources, $scripts, $message, $exclusions)) {
                    $provider_added = true;
                }
            }

            if ($explicit_wp_provider_added || $provider_added) {
                foreach ($symbols as $symbol) {
                    $this->runtime_js_scan_add_missing_global_consumer_suggestions($suggestions, $seen, $symbol, $source, $message, $detail, $scripts, $exclusions);
                }
                continue;
            }

            $inventory_provider_added = false;
            foreach ($symbols as $symbol) {
                if ($this->runtime_js_scan_add_inventory_symbol_provider_suggestions($suggestions, $seen, $symbol, $scripts, $message, $exclusions)) {
                    $inventory_provider_added = true;
                }
            }

            if ($inventory_provider_added) {
                continue;
            }

            if (empty($direct_sources)) {
                $reason = 'Runtime Scan did not find an external plugin/theme stack source, so it inspected scanned inline handles/sourceURL markers and final HTML adjacency for the same error in this same pass.';
                $this->runtime_js_scan_add_inline_stack_frame_suggestions($suggestions, $seen, $scripts, (string) $detail . "\n" . (string) $message, $message, $reason, $exclusions, 'recommended');
                foreach ($symbols as $symbol) {
                    $this->runtime_js_scan_add_html_adjacency_suggestions($suggestions, $seen, $symbol, $scripts, $source, $message, $exclusions);
                }
                if ($is_explicit_runtime_error) {
                    $this->runtime_js_scan_add_evidence_source_suggestions($suggestions, $seen, $source, $message, $detail, $exclusions, $scripts);
                }
                continue;
            }

            $discovered_provider_added = false;
            foreach ($symbols as $symbol) {
                $definitions = $this->runtime_js_scan_find_symbol_definitions_for_owners($symbol, $direct_owners);
                foreach ($definitions as $definition) {
                    if (empty($definition['owner']) || !is_array($definition['owner'])) {
                        continue;
                    }
                    $def_owner = $definition['owner'];
                    $def_kind = isset($def_owner['kind']) ? (string) $def_owner['kind'] : '';
                    $def_slug = isset($def_owner['slug']) ? sanitize_key((string) $def_owner['slug']) : '';
                    $def_fragment = isset($definition['fragment']) ? (string) $definition['fragment'] : '';
                    if ('' === $def_kind || '' === $def_slug || '' === $def_fragment) {
                        continue;
                    }

                    foreach ($direct_sources as $direct) {
                        if (empty($direct['owner']) || !is_array($direct['owner'])) {
                            continue;
                        }
                        $src_owner = $direct['owner'];
                        $src_kind = isset($src_owner['kind']) ? (string) $src_owner['kind'] : '';
                        $src_slug = isset($src_owner['slug']) ? sanitize_key((string) $src_owner['slug']) : '';
                        if ($src_kind !== $def_kind || $src_slug !== $def_slug) {
                            continue;
                        }

                        $this->runtime_js_scan_add_suggestion(
                            $suggestions,
                            $seen,
                            $def_fragment,
                            'same-owner exact symbol provider',
                            isset($definition['source']) ? (string) $definition['source'] : '',
                            $message,
                            'The error stack identifies this plugin/theme owner, and active code discovery found the exact file that provides the missing symbol "' . sanitize_text_field($symbol) . '". Keep this provider available before the direct consumer.',
                            $exclusions,
                            'recommended'
                        );
                        $this->runtime_js_scan_add_missing_global_consumer_suggestions($suggestions, $seen, $symbol, $source, $message, $detail, $scripts, $exclusions);
                        $discovered_provider_added = true;
                        break 2;
                    }
                }
                if ($discovered_provider_added) {
                    break;
                }
            }

            if ($discovered_provider_added) {
                continue;
            }

            if ($is_explicit_runtime_error) {
                $this->runtime_js_scan_add_evidence_source_suggestions(
                    $suggestions,
                    $seen,
                    $source,
                    $message,
                    $detail,
                    $exclusions,
                    $scripts
                );
            }
        }

        $suggestions = $this->runtime_js_scan_finalize_suggestions($suggestions);

        $missing = 0;
        foreach ($suggestions as $suggestion) {
            if (empty($suggestion['alreadyExcluded'])) {
                $missing++;
            }
        }

        return array(
            'available'              => true,
            'source'                 => 'browser-runtime',
            'suggestion_count'       => count($suggestions),
            'missing_count'          => (int) $missing,
            'already_excluded_count' => count($suggestions) - (int) $missing,
            'suggestions'            => $suggestions,
        );
    }

}
