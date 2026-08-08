<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Runtime_JS_Rules_Trait
{
    /** @var array<int,array<string,mixed>> */
    private $runtime_js_scan_current_scripts = array();

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


    private function runtime_js_scan_script_effective_strategy(array $script)
    {
        if (!empty($script['delayed'])) {
            return 'delay';
        }
        if (!empty($script['async'])) {
            return 'async';
        }
        $strategy = strtolower(trim((string) ($script['strategy'] ?? '')));
        if (!empty($script['defer']) || 'defer' === $strategy) {
            return 'defer';
        }
        return 'blocking';
    }

    private function runtime_js_scan_script_matches_candidate(array $script, $candidate)
    {
        $candidate = strtolower($this->runtime_js_scan_clean_console_candidate((string) $candidate));
        if ('' === $candidate) {
            return false;
        }

        foreach (array('src', 'handle', 'id') as $field) {
            $value = strtolower($this->runtime_js_scan_clean_console_candidate((string) ($script[$field] ?? '')));
            if ('' === $value) {
                continue;
            }
            if ($value === $candidate || false !== strpos($value, $candidate) || (strlen($value) >= 4 && false !== strpos($candidate, $value))) {
                return true;
            }
            $value_base = strtolower($this->runtime_js_scan_basename_from_source($value));
            $candidate_base = strtolower($this->runtime_js_scan_basename_from_source($candidate));
            if ('' !== $value_base && '' !== $candidate_base && $value_base === $candidate_base) {
                return true;
            }
        }
        return false;
    }

    private function runtime_js_scan_matching_inventory_scripts($candidate)
    {
        $matches = array();
        foreach ((array) $this->runtime_js_scan_current_scripts as $index => $script) {
            if (!is_array($script) || !$this->runtime_js_scan_script_matches_candidate($script, $candidate)) {
                continue;
            }
            $script['_inventoryIndex'] = (int) $index;
            $matches[] = $script;
        }
        return $matches;
    }

    private function runtime_js_scan_runtime_state_for_candidate($candidate)
    {
        $state = array(
            'matched'    => false,
            'matchCount' => 0,
            'delayed'    => false,
            'deferred'   => false,
            'async'      => false,
            'blocking'   => false,
            'strategies' => array(),
        );
        foreach ($this->runtime_js_scan_matching_inventory_scripts($candidate) as $script) {
            $strategy = $this->runtime_js_scan_script_effective_strategy($script);
            $state['matched'] = true;
            $state['matchCount']++;
            $state['strategies'][$strategy] = true;
            if ('delay' === $strategy) {
                $state['delayed'] = true;
            } elseif ('defer' === $strategy) {
                $state['deferred'] = true;
            } elseif ('async' === $strategy) {
                $state['async'] = true;
            } else {
                $state['blocking'] = true;
            }
        }
        $state['strategies'] = array_keys($state['strategies']);
        return $state;
    }

    private function runtime_js_scan_candidate_matches_error_source($candidate, $source)
    {
        $source = $this->runtime_js_scan_sanitize_source((string) $source);
        if ('' === $source) {
            return false;
        }

        $candidate = $this->runtime_js_scan_clean_console_candidate((string) $candidate);
        if ('' === $candidate) {
            return false;
        }

        if ($this->runtime_js_scan_script_matches_candidate(array('src' => $source), $candidate)) {
            return true;
        }

        foreach ($this->runtime_js_scan_matching_inventory_scripts($candidate) as $script) {
            if ($this->runtime_js_scan_script_matches_candidate($script, $source)) {
                return true;
            }
        }
        return false;
    }

    private function runtime_js_scan_find_inventory_script_by_handle($handle, array $scripts)
    {
        $handle = sanitize_key((string) $handle);
        if ('' === $handle) {
            return array();
        }
        foreach ($scripts as $index => $script) {
            if (!is_array($script) || $handle !== sanitize_key((string) ($script['handle'] ?? ''))) {
                continue;
            }
            $script['_inventoryIndex'] = (int) $index;
            return $script;
        }
        return array();
    }

    private function runtime_js_scan_dependency_suggestion_for_script(array $script)
    {
        $src = (string) ($script['src'] ?? '');
        if ('' !== $src) {
            $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($src, 5);
            if ('' !== $fragment) {
                return $fragment;
            }
        }
        $handle = sanitize_key((string) ($script['handle'] ?? ''));
        if ('' !== $handle) {
            return $handle;
        }
        return sanitize_text_field((string) ($script['id'] ?? ''));
    }

    private function runtime_js_scan_strong_suggestion_for_script(array $script)
    {
        $handle = sanitize_key((string) ($script['handle'] ?? ''));
        if ('' !== $handle && !$this->runtime_js_scan_is_generic_token($handle)) {
            return $handle;
        }
        return $this->runtime_js_scan_dependency_suggestion_for_script($script);
    }

    private function runtime_js_scan_add_persistent_exact_error_source_suggestion(&$suggestions, &$seen, $source, $message, $detail, array $exclusions)
    {
        if (!$this->runtime_js_scan_is_explicit_runtime_error($message, $detail)) {
            return false;
        }

        $source = $this->runtime_js_scan_sanitize_source((string) $source);
        if ('' === $source) {
            return false;
        }

        $safeguards = $this->runtime_js_scan_normalize_safeguard_lists($exclusions);
        $candidates = array();
        $candidate_seen = array();
        $push = function ($candidate) use (&$candidates, &$candidate_seen) {
            $candidate = $this->runtime_js_scan_clean_console_candidate((string) $candidate);
            if ('' === $candidate || $this->runtime_js_scan_is_ultracache_runtime_helper_source($candidate) || $this->runtime_js_scan_is_generic_token($candidate)) {
                return;
            }
            $key = strtolower($candidate);
            if (isset($candidate_seen[$key])) {
                return;
            }
            $candidate_seen[$key] = true;
            $candidates[] = $candidate;
        };

        $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($source, 6);
        if ('' !== $fragment) {
            $push($fragment);
        }
        foreach ($this->runtime_js_scan_matching_inventory_scripts($source) as $script) {
            $push($this->runtime_js_scan_dependency_suggestion_for_script($script));
            $push((string) ($script['handle'] ?? ''));
            $push((string) ($script['id'] ?? ''));
        }

        foreach ($candidates as $candidate) {
            if (!$this->runtime_js_scan_exclusion_already_matches($candidate, $safeguards['fallback'])) {
                continue;
            }
            if (!$this->runtime_js_scan_candidate_matches_error_source($candidate, $source)) {
                continue;
            }
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $candidate,
                'persistent exact runtime source',
                $source,
                trim((string) $message . "\n" . (string) $detail),
                'The same exact script still appears as the source of a browser runtime error even though the current Do Not Defer or Delay list already covers it. Keep this finding visible and use the scanned execution state plus dependency/file analysis to decide the next fix instead of treating the configured exclusion as proof that the error is resolved.',
                $exclusions,
                'recommended',
                'exclusion'
            );
            return true;
        }

        return false;
    }

    private function runtime_js_scan_declared_dependency_preferred_target($provider_strategy, $consumer_strategy, $strong_only = false)
    {
        $provider_strategy = strtolower(trim((string) $provider_strategy));
        $consumer_strategy = strtolower(trim((string) $consumer_strategy));

        if ($strong_only) {
            if ('delay' === $provider_strategy && in_array($consumer_strategy, array('blocking', 'defer'), true)) {
                return 'force';
            }
            if ('defer' === $provider_strategy && 'blocking' === $consumer_strategy) {
                return 'exclusion';
            }
            return '';
        }

        if ('delay' === $provider_strategy && 'delay' !== $consumer_strategy) {
            return 'force';
        }
        if ('defer' === $provider_strategy && 'blocking' === $consumer_strategy) {
            return 'exclusion';
        }
        if ('async' === $provider_strategy && 'delay' !== $consumer_strategy) {
            return 'exclusion';
        }
        return '';
    }

    private function runtime_js_scan_add_declared_dependency_risk_suggestions(&$suggestions, &$seen, array $scripts, array $exclusions, $prefer_handle = false)
    {
        $added = false;
        foreach ($scripts as $consumer) {
            if (!is_array($consumer) || empty($consumer['deps'])) {
                continue;
            }
            $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($consumer);
            foreach ((array) $consumer['deps'] as $dependency_handle) {
                $provider = $this->runtime_js_scan_find_inventory_script_by_handle($dependency_handle, $scripts);
                if (empty($provider)) {
                    continue;
                }
                $provider_strategy = $this->runtime_js_scan_script_effective_strategy($provider);
                $preferred_target = $this->runtime_js_scan_declared_dependency_preferred_target($provider_strategy, $consumer_strategy, $prefer_handle);
                if ('' === $preferred_target) {
                    continue;
                }
                $suggestion = $prefer_handle ? $this->runtime_js_scan_strong_suggestion_for_script($provider) : $this->runtime_js_scan_dependency_suggestion_for_script($provider);
                if ('' === $suggestion || $this->runtime_js_scan_is_ultracache_runtime_helper_source($suggestion)) {
                    continue;
                }
                $consumer_name = sanitize_text_field((string) ($consumer['handle'] ?? $consumer['id'] ?? $consumer['src'] ?? 'consumer script'));
                $provider_name = sanitize_text_field((string) ($provider['handle'] ?? $dependency_handle));
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $suggestion,
                    'declared dependency ' . $provider_name,
                    (string) ($provider['src'] ?? ''),
                    'Page dependency graph found an execution-order conflict for provider ' . $provider_name . ' required by ' . $consumer_name . '.',
                    'WordPress registered "' . $provider_name . '" as a dependency of "' . $consumer_name . '", but the scanned page executes the provider as ' . $provider_strategy . ' while the consumer executes as ' . $consumer_strategy . '. The provider can therefore run too late for the declared dependency. Use the least-invasive earlier strategy proposed by this finding and rescan.',
                    $exclusions,
                    'recommended',
                    $preferred_target
                );
                $added = true;
                if (count($suggestions) >= 80) {
                    return true;
                }
            }
        }
        return $added;
    }

    private function runtime_js_scan_normalize_lifecycle_event($event)
    {
        $event = strtolower(trim(html_entity_decode((string) $event, ENT_QUOTES, 'UTF-8')));
        if ('' === $event || strlen($event) > 120) {
            return '';
        }
        $event = preg_split('/\\s+/', $event)[0] ?? '';
        $event = trim((string) $event);
        if ('' === $event) {
            return '';
        }
        if (false !== strpos($event, '.')) {
            $event = (string) strstr($event, '.', true);
        }
        if ('' === $event || in_array($event, array(
            'click', 'dblclick', 'mousedown', 'mouseup', 'mousemove', 'mouseover', 'mouseout', 'mouseenter', 'mouseleave',
            'touchstart', 'touchmove', 'touchend', 'pointerdown', 'pointerup', 'pointermove', 'keydown', 'keyup', 'keypress',
            'scroll', 'resize', 'focus', 'blur', 'change', 'input', 'submit', 'load', 'error'
        ), true)) {
            return '';
        }
        if (false === strpos($event, '/') && false === strpos($event, ':') && false === strpos($event, '-') && !preg_match('/(?:init|ready|loaded|render|mount|frontend|elementor|woocommerce|wc_)/', $event)) {
            return '';
        }
        return sanitize_text_field($event);
    }

    private function runtime_js_scan_is_strong_lifecycle_event($event)
    {
        $event = strtolower(trim((string) $event));
        if ('' === $event) {
            return false;
        }

        return (bool) preg_match('/(?:^|[\/_:.-])(?:init|initialize|initialized|ready|loaded|mount|mounted|bootstrap|boot)(?:$|[\/_:.-])|element_ready|components?:init/', $event);
    }

    private function runtime_js_scan_extract_js_call_arguments($content, $open_paren_offset, $max_chars = 1600)
    {
        $content = (string) $content;
        $length = strlen($content);
        $open_paren_offset = (int) $open_paren_offset;
        if ($open_paren_offset < 0 || $open_paren_offset >= $length || '(' !== $content[$open_paren_offset]) {
            return array();
        }

        $limit = min($length, $open_paren_offset + max(64, (int) $max_chars));
        $args = array();
        $current = '';
        $paren_depth = 1;
        $bracket_depth = 0;
        $brace_depth = 0;
        $quote = '';
        $escape = false;

        for ($i = $open_paren_offset + 1; $i < $limit; $i++) {
            $char = $content[$i];
            if ('' !== $quote) {
                $current .= $char;
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ('\\' === $char) {
                    $escape = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }

            if (in_array($char, array("'", '"', '`'), true)) {
                $quote = $char;
                $current .= $char;
                continue;
            }
            if ('(' === $char) {
                $paren_depth++;
                $current .= $char;
                continue;
            }
            if (')' === $char) {
                $paren_depth--;
                if (0 === $paren_depth) {
                    $args[] = trim($current);
                    return $args;
                }
                $current .= $char;
                continue;
            }
            if ('[' === $char) {
                $bracket_depth++;
                $current .= $char;
                continue;
            }
            if (']' === $char) {
                $bracket_depth = max(0, $bracket_depth - 1);
                $current .= $char;
                continue;
            }
            if ('{' === $char) {
                $brace_depth++;
                $current .= $char;
                continue;
            }
            if ('}' === $char) {
                $brace_depth = max(0, $brace_depth - 1);
                $current .= $char;
                continue;
            }
            if (',' === $char && 1 === $paren_depth && 0 === $bracket_depth && 0 === $brace_depth) {
                $args[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }

        return array();
    }

    private function runtime_js_scan_extract_js_brace_block($content, $open_brace_offset, $max_chars = 6000)
    {
        $content = (string) $content;
        $length = strlen($content);
        $open_brace_offset = (int) $open_brace_offset;
        if ($open_brace_offset < 0 || $open_brace_offset >= $length || '{' !== $content[$open_brace_offset]) {
            return '';
        }

        $limit = min($length, $open_brace_offset + max(128, (int) $max_chars));
        $depth = 1;
        $quote = '';
        $escape = false;
        for ($i = $open_brace_offset + 1; $i < $limit; $i++) {
            $char = $content[$i];
            if ('' !== $quote) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ('\\' === $char) {
                    $escape = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if (in_array($char, array("'", '"', '`'), true)) {
                $quote = $char;
                continue;
            }
            if ('{' === $char) {
                $depth++;
                continue;
            }
            if ('}' === $char) {
                $depth--;
                if (0 === $depth) {
                    return substr($content, $open_brace_offset, ($i - $open_brace_offset) + 1);
                }
            }
        }
        return '';
    }

    private function runtime_js_scan_extract_literal_js_string($value)
    {
        $value = trim((string) $value);
        if (strlen($value) < 2) {
            return '';
        }
        $quote = $value[0];
        if (($quote !== "'" && $quote !== '"') || substr($value, -1) !== $quote) {
            return '';
        }
        $literal = substr($value, 1, -1);
        if (false !== strpos($literal, '\\')) {
            $literal = stripcslashes($literal);
        }
        return (string) $literal;
    }

    private function runtime_js_scan_find_js_brace_close_offset($content, $open_brace_offset)
    {
        $content = (string) $content;
        $length = strlen($content);
        $open_brace_offset = (int) $open_brace_offset;
        if ($open_brace_offset < 0 || $open_brace_offset >= $length || '{' !== $content[$open_brace_offset]) {
            return -1;
        }

        $depth = 1;
        $quote = '';
        $escape = false;
        for ($i = $open_brace_offset + 1; $i < $length; $i++) {
            $char = $content[$i];
            if ('' !== $quote) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ('\\' === $char) {
                    $escape = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if (in_array($char, array("'", '"', '`'), true)) {
                $quote = $char;
                continue;
            }
            if ('{' === $char) {
                $depth++;
            } elseif ('}' === $char) {
                $depth--;
                if (0 === $depth) {
                    return $i;
                }
            }
        }
        return -1;
    }

    private function runtime_js_scan_offset_inside_callback_pattern($content, $offset, $pattern)
    {
        $content = (string) $content;
        $offset = max(0, (int) $offset);
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return false;
        }
        foreach ($matches as $match) {
            $full = (string) ($match[0][0] ?? '');
            $start = (int) ($match[0][1] ?? -1);
            if ($start < 0 || $start > $offset) {
                continue;
            }
            $relative_brace = strrpos($full, '{');
            if (false === $relative_brace) {
                continue;
            }
            $open = $start + $relative_brace;
            $close = $this->runtime_js_scan_find_js_brace_close_offset($content, $open);
            if ($close > $offset) {
                return true;
            }
        }
        return false;
    }

    private function runtime_js_scan_direct_scheduling_context_for_offset($content, $offset)
    {
        $content = (string) $content;
        $offset = max(0, (int) $offset);
        $callback = '(?:function\\s*\\([^)]*\\)|(?:\\([^)]*\\)|[A-Za-z_$][A-Za-z0-9_$]*)\\s*=>)\\s*\\{';

        $dom_ready_patterns = array(
            '/(?:jQuery|\\$)\\s*\\(\\s*' . $callback . '/i',
            '/\\.ready\\s*\\(\\s*' . $callback . '/i',
            '/addEventListener\\s*\\(\\s*([\\\'\"])DOMContentLoaded\\1\\s*,\\s*' . $callback . '/i',
        );
        foreach ($dom_ready_patterns as $pattern) {
            if ($this->runtime_js_scan_offset_inside_callback_pattern($content, $offset, $pattern)) {
                return 'dom_ready';
            }
        }

        $window_load_patterns = array(
            '/addEventListener\\s*\\(\\s*([\\\'\"])load\\1\\s*,\\s*' . $callback . '/i',
            '/(?:jQuery|\\$)\\s*\\(\\s*window\\s*\\)\\s*\\.(?:on|one)\\s*\\(\\s*([\\\'\"])load\\1\\s*,\\s*' . $callback . '/i',
        );
        foreach ($window_load_patterns as $pattern) {
            if ($this->runtime_js_scan_offset_inside_callback_pattern($content, $offset, $pattern)) {
                return 'window_load';
            }
        }

        $deferred_callback_patterns = array(
            '/\\bset(?:Timeout|Interval)\\s*\\(\\s*' . $callback . '/i',
            '/\\.(?:then|catch|finally|done|fail|always|success|complete)\\s*\\(\\s*' . $callback . '/i',
            '/\\.(?:on|one)\\s*\\(\\s*([\\\'\"])[^\\\'\"]{1,120}\\1\\s*,\\s*' . $callback . '/i',
            '/addEventListener\\s*\\(\\s*([\\\'\"])[^\\\'\"]{1,120}\\1\\s*,\\s*' . $callback . '/i',
        );
        foreach ($deferred_callback_patterns as $pattern) {
            if ($this->runtime_js_scan_offset_inside_callback_pattern($content, $offset, $pattern)) {
                return 'callback';
            }
        }

        $prefix = substr($content, max(0, $offset - 900), min(900, $offset));
        if (preg_match('/(?:jQuery|\\$)\\s*\\(\\s*(?:\\([^)]*\\)|[A-Za-z_$][A-Za-z0-9_$]*)?\\s*=>\\s*[^;{}]{0,700}$/s', $prefix)
            || preg_match('/\\.ready\\s*\\(\\s*(?:\\([^)]*\\)|[A-Za-z_$][A-Za-z0-9_$]*)?\\s*=>\\s*[^;{}]{0,700}$/s', $prefix)) {
            return 'dom_ready';
        }
        if (preg_match('/\\bset(?:Timeout|Interval)\\s*\\(\\s*(?:\\([^)]*\\)|[A-Za-z_$][A-Za-z0-9_$]*)?\\s*=>\\s*[^;{}]{0,700}$/s', $prefix)
            || preg_match('/\\.(?:then|catch|finally|done|fail|always|success|complete)\\s*\\(\\s*(?:\\([^)]*\\)|[A-Za-z_$][A-Za-z0-9_$]*)?\\s*=>\\s*[^;{}]{0,700}$/s', $prefix)) {
            return 'callback';
        }

        return '';
    }

    private function runtime_js_scan_containing_callable_descriptor($content, $offset)
    {
        $content = (string) $content;
        $offset = max(0, (int) $offset);
        $patterns = array(
            array('type' => 'function', 'pattern' => '/\\bfunction\\s+([A-Za-z_$][A-Za-z0-9_$]*)\\s*\\([^)]*\\)\\s*\\{/'),
            array('type' => 'function', 'pattern' => '/\\b([A-Za-z_$][A-Za-z0-9_$]*)\\s*[:=]\\s*function\\s*\\([^)]*\\)\\s*\\{/'),
            array('type' => 'function', 'pattern' => '/\\b(?:const|let|var)\\s+([A-Za-z_$][A-Za-z0-9_$]*)\\s*=\\s*(?:async\\s*)?(?:\\([^)]*\\)|[A-Za-z_$][A-Za-z0-9_$]*)\\s*=>\\s*\\{/'),
            array('type' => 'method', 'pattern' => '/\\b([A-Za-z_$][A-Za-z0-9_$]*)\\s*\\([^;{}]{0,320}\\)\\s*\\{/'),
        );
        $reserved = array('if', 'for', 'while', 'switch', 'catch', 'with', 'function');
        $best = array();
        $best_open = -1;

        foreach ($patterns as $entry) {
            if (!preg_match_all($entry['pattern'], $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches as $match) {
                $name = (string) ($match[1][0] ?? '');
                if ('' === $name || in_array(strtolower($name), $reserved, true)) {
                    continue;
                }
                $full = (string) ($match[0][0] ?? '');
                $start = (int) ($match[0][1] ?? -1);
                if ($start < 0 || $start > $offset) {
                    continue;
                }
                $relative_brace = strrpos($full, '{');
                if (false === $relative_brace) {
                    continue;
                }
                $open = $start + $relative_brace;
                if ($open <= $best_open || $open >= $offset) {
                    continue;
                }
                $close = $this->runtime_js_scan_find_js_brace_close_offset($content, $open);
                if ($close <= $offset) {
                    continue;
                }
                $best = array(
                    'type' => (string) $entry['type'],
                    'name' => $name,
                    'start' => $start,
                    'open' => $open,
                    'close' => $close,
                );
                $best_open = $open;
            }
        }

        if (empty($best) || 'method' !== ($best['type'] ?? '')) {
            return $best;
        }

        if (preg_match_all('/\\bclass\\s+([A-Za-z_$][A-Za-z0-9_$]*)[^\\{]*\\{/', $content, $classes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $class_best_open = -1;
            foreach ($classes as $class_match) {
                $full = (string) ($class_match[0][0] ?? '');
                $start = (int) ($class_match[0][1] ?? -1);
                if ($start < 0 || $start > $best['open']) {
                    continue;
                }
                $relative_brace = strrpos($full, '{');
                if (false === $relative_brace) {
                    continue;
                }
                $open = $start + $relative_brace;
                $close = $this->runtime_js_scan_find_js_brace_close_offset($content, $open);
                if ($open > $class_best_open && $close > $best['close']) {
                    $best['class'] = (string) ($class_match[1][0] ?? '');
                    $class_best_open = $open;
                }
            }
        }

        return $best;
    }

    private function runtime_js_scan_callable_scheduled_timing($content, array $descriptor)
    {
        $content = (string) $content;
        $name = (string) ($descriptor['name'] ?? '');
        if ('' === $name) {
            return 'unknown';
        }

        $call_patterns = array();
        if ('method' === ($descriptor['type'] ?? '') && !empty($descriptor['class'])) {
            $instances = array();
            $class_pattern = preg_quote((string) $descriptor['class'], '/');
            if (preg_match_all('/\\b((?:window\\.)?[A-Za-z_$][A-Za-z0-9_$]*)\\s*=\\s*new\\s+' . $class_pattern . '\\b/', $content, $instance_matches, PREG_SET_ORDER)) {
                foreach ($instance_matches as $instance_match) {
                    $instance = (string) ($instance_match[1] ?? '');
                    if ('' === $instance) {
                        continue;
                    }
                    $instances[$instance] = true;
                    if (false !== strpos($instance, '.')) {
                        $instances[substr($instance, strrpos($instance, '.') + 1)] = true;
                    }
                }
            }
            foreach (array_keys($instances) as $instance) {
                $call_patterns[] = '/\\b' . preg_quote($instance, '/') . '\\s*\\.\\s*' . preg_quote($name, '/') . '\\s*\\(/';
            }
        } elseif ('function' === ($descriptor['type'] ?? '')) {
            $call_patterns[] = '/\\b' . preg_quote($name, '/') . '\\s*\\(/';
        }

        if (empty($call_patterns)) {
            return 'unknown';
        }

        $timings = array();
        foreach ($call_patterns as $pattern) {
            if (!preg_match_all($pattern, $content, $calls, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ((array) ($calls[0] ?? array()) as $call) {
                $call_offset = (int) ($call[1] ?? -1);
                if ($call_offset < 0 || abs($call_offset - (int) ($descriptor['start'] ?? -99999)) < 8) {
                    continue;
                }
                $timing = $this->runtime_js_scan_direct_scheduling_context_for_offset($content, $call_offset);
                if ('' === $timing) {
                    $outer = $this->runtime_js_scan_containing_callable_descriptor($content, $call_offset);
                    $timing = empty($outer) ? 'immediate' : 'unknown';
                }
                $timings[$timing] = true;
            }
        }

        if (empty($timings)) {
            return 'unknown';
        }
        if (1 === count($timings)) {
            return (string) array_key_first($timings);
        }
        return 'mixed';
    }

    private function runtime_js_scan_emit_timing_for_offset($content, $offset)
    {
        $timing = $this->runtime_js_scan_direct_scheduling_context_for_offset($content, $offset);
        if ('' !== $timing) {
            return $timing;
        }

        $descriptor = $this->runtime_js_scan_containing_callable_descriptor($content, $offset);
        if (empty($descriptor)) {
            return 'immediate';
        }

        return $this->runtime_js_scan_callable_scheduled_timing($content, $descriptor);
    }

    private function runtime_js_scan_merge_emitter_timing(array &$timings, $event, $timing)
    {
        $event = trim((string) $event);
        $timing = strtolower(trim((string) $timing));
        if ('' === $event) {
            return;
        }
        if (!in_array($timing, array('immediate', 'dom_ready', 'window_load', 'callback', 'unknown', 'mixed'), true)) {
            $timing = 'unknown';
        }
        if (!isset($timings[$event])) {
            $timings[$event] = $timing;
            return;
        }
        if ($timings[$event] !== $timing) {
            $timings[$event] = 'mixed';
        }
    }

    private function runtime_js_scan_extract_wrapped_lifecycle_emit_records($content)
    {
        $content = (string) $content;
        if ('' === $content) {
            return array();
        }
        if (false === strpos($content, 'dispatchEvent') || (false === strpos($content, 'CustomEvent') && false === strpos($content, 'new Event'))) {
            return array();
        }

        $wrapper_indexes = array();
        $definition_pattern = '/\\b([A-Za-z_$][A-Za-z0-9_$]*)\\s*\\(([^()]{1,320})\\)\\s*\\{/';
        if (preg_match_all($definition_pattern, $content, $definitions, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($definitions as $definition) {
                $name = (string) ($definition[1][0] ?? '');
                if ('' === $name || in_array(strtolower($name), array('if', 'for', 'while', 'switch', 'catch', 'with'), true)) {
                    continue;
                }
                $params_raw = (string) ($definition[2][0] ?? '');
                $params = array_values(array_filter(array_map('trim', explode(',', $params_raw)), 'strlen'));
                if (empty($params)) {
                    continue;
                }
                $definition_text = (string) ($definition[0][0] ?? '');
                $definition_offset = (int) ($definition[0][1] ?? 0);
                $relative_brace = strrpos($definition_text, '{');
                if (false === $relative_brace) {
                    continue;
                }
                $body_probe = $this->runtime_js_scan_extract_js_brace_block($content, $definition_offset + $relative_brace);
                if ('' === $body_probe) {
                    continue;
                }
                foreach ($params as $index => $param) {
                    if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $param)) {
                        continue;
                    }
                    $param_pattern = preg_quote($param, '/');
                    if (!preg_match('/\\bdispatchEvent\\s*\\(\\s*new\\s+(?:CustomEvent|Event)\\s*\\(\\s*' . $param_pattern . '\\b/', $body_probe)) {
                        continue;
                    }
                    if (!isset($wrapper_indexes[$name])) {
                        $wrapper_indexes[$name] = array();
                    }
                    $wrapper_indexes[$name][(int) $index] = true;
                }
            }
        }

        if (empty($wrapper_indexes)) {
            return array();
        }

        $records = array();
        foreach ($wrapper_indexes as $wrapper_name => $indexes) {
            $call_pattern = '/(?:\\.|\\b)' . preg_quote((string) $wrapper_name, '/') . '\\s*\\(/';
            if (!preg_match_all($call_pattern, $content, $calls, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ((array) ($calls[0] ?? array()) as $call) {
                $call_text = (string) ($call[0] ?? '');
                $call_offset = (int) ($call[1] ?? -1);
                if ($call_offset < 0) {
                    continue;
                }
                $relative_open = strrpos($call_text, '(');
                if (false === $relative_open) {
                    continue;
                }
                $args = $this->runtime_js_scan_extract_js_call_arguments($content, $call_offset + $relative_open);
                if (empty($args)) {
                    continue;
                }
                foreach (array_keys($indexes) as $index) {
                    if (!isset($args[$index])) {
                        continue;
                    }
                    $literal = $this->runtime_js_scan_extract_literal_js_string($args[$index]);
                    $event = $this->runtime_js_scan_normalize_lifecycle_event($literal);
                    if ('' !== $event) {
                        $records[] = array('event' => $event, 'offset' => $call_offset);
                    }
                }
            }
        }

        return $records;
    }

    private function runtime_js_scan_extract_wrapped_lifecycle_emit_events($content)
    {
        $events = array();
        foreach ($this->runtime_js_scan_extract_wrapped_lifecycle_emit_records($content) as $record) {
            $event = (string) ($record['event'] ?? '');
            if ('' !== $event) {
                $events[$event] = true;
            }
        }
        return array_keys($events);
    }

    private function runtime_js_scan_extract_lifecycle_emitter_evidence($content)
    {
        $content = (string) $content;
        $timings = array();
        $records = array();
        $patterns = array(
            '/\\.(?:trigger|triggerHandler)\\s*\\(\\s*([\\\'\"])([^\\\'\"]{2,120})\\1/i',
            '/\\bdispatchEvent\\s*\\(\\s*new\\s+(?:CustomEvent|Event)\\s*\\(\\s*([\\\'\"])([^\\\'\"]{2,120})\\1/i',
        );
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches as $match) {
                $event = $this->runtime_js_scan_normalize_lifecycle_event((string) ($match[2][0] ?? ''));
                $offset = (int) ($match[0][1] ?? -1);
                if ('' !== $event && $offset >= 0) {
                    $records[] = array('event' => $event, 'offset' => $offset);
                }
            }
        }
        foreach ($this->runtime_js_scan_extract_wrapped_lifecycle_emit_records($content) as $record) {
            $records[] = $record;
        }

        foreach ($records as $record) {
            $event = (string) ($record['event'] ?? '');
            $offset = (int) ($record['offset'] ?? -1);
            if ('' === $event || $offset < 0) {
                continue;
            }
            $timing = $this->runtime_js_scan_is_strong_lifecycle_event($event)
                ? $this->runtime_js_scan_emit_timing_for_offset($content, $offset)
                : 'unknown';
            $this->runtime_js_scan_merge_emitter_timing($timings, $event, $timing);
        }

        return $timings;
    }

    private function runtime_js_scan_extract_lifecycle_events($content, $mode)
    {
        $content = (string) $content;
        $mode = 'emit' === $mode ? 'emit' : 'listen';
        if ('' === $content) {
            return array();
        }
        if ('emit' === $mode) {
            return array_keys($this->runtime_js_scan_extract_lifecycle_emitter_evidence($content));
        }

        $patterns = array(
            '/\\.(?:on|one)\\s*\\(\\s*([\\\'\"])([^\\\'\"]{2,120})\\1/i',
            '/\\baddEventListener\\s*\\(\\s*([\\\'\"])([^\\\'\"]{2,120})\\1/i',
        );
        $events = array();
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $event = $this->runtime_js_scan_normalize_lifecycle_event((string) ($match[2] ?? ''));
                if ('' !== $event) {
                    $events[$event] = true;
                }
            }
        }
        return array_keys($events);
    }

    private function runtime_js_scan_lifecycle_preferred_target($listener_strategy, $emitter_strategy, $strong_only = false, $emitter_timing = 'unknown')
    {
        $listener_strategy = strtolower(trim((string) $listener_strategy));
        $emitter_strategy = strtolower(trim((string) $emitter_strategy));
        $emitter_timing = strtolower(trim((string) $emitter_timing));

        if ($strong_only) {
            if ('async' === $emitter_strategy || in_array($emitter_timing, array('callback', 'unknown', 'mixed', ''), true)) {
                return '';
            }
            if ('delay' === $listener_strategy) {
                if ('immediate' === $emitter_timing && 'blocking' === $emitter_strategy) {
                    return 'exclusion';
                }
                if (in_array($emitter_timing, array('dom_ready', 'window_load'), true) && in_array($emitter_strategy, array('blocking', 'defer'), true)) {
                    return 'force';
                }
                if ('immediate' === $emitter_timing && 'defer' === $emitter_strategy) {
                    return 'force';
                }
            }
            if ('defer' === $listener_strategy && 'immediate' === $emitter_timing && 'blocking' === $emitter_strategy) {
                return 'exclusion';
            }
            return '';
        }

        if ('delay' === $listener_strategy && 'delay' !== $emitter_strategy) {
            return 'force';
        }
        if ('defer' === $listener_strategy && 'blocking' === $emitter_strategy) {
            return 'exclusion';
        }
        if ('async' === $listener_strategy && in_array($emitter_strategy, array('blocking', 'defer'), true)) {
            return 'exclusion';
        }
        return '';
    }

    private function runtime_js_scan_same_inventory_script(array $left, array $right)
    {
        $left_handle = sanitize_key((string) ($left['handle'] ?? ''));
        $right_handle = sanitize_key((string) ($right['handle'] ?? ''));
        if ('' !== $left_handle && '' !== $right_handle && $left_handle === $right_handle) {
            return true;
        }

        $left_src = strtolower($this->runtime_js_scan_clean_console_candidate((string) ($left['src'] ?? '')));
        $right_src = strtolower($this->runtime_js_scan_clean_console_candidate((string) ($right['src'] ?? '')));
        if ('' === $left_src || '' === $right_src) {
            return false;
        }
        $left_src = preg_replace('/[?#].*$/', '', $left_src);
        $right_src = preg_replace('/[?#].*$/', '', $right_src);
        return '' !== $left_src && $left_src === $right_src;
    }

    private function runtime_js_scan_add_lifecycle_dependency_risk_suggestions_from_evidence(&$suggestions, &$seen, array $scripts, array $exclusions, array $listeners, array $emitters, $prefer_handle = false, array $emitter_timings = array())
    {
        $added = false;
        foreach ($listeners as $event => $listener_indexes) {
            if (empty($emitters[$event])) {
                continue;
            }
            foreach ((array) $listener_indexes as $listener_index) {
                $listener_index = (int) $listener_index;
                if (!isset($scripts[$listener_index]) || !is_array($scripts[$listener_index])) {
                    continue;
                }
                $listener_script = $scripts[$listener_index];
                $listener_strategy = $this->runtime_js_scan_script_effective_strategy($listener_script);
                foreach ((array) $emitters[$event] as $emitter_index) {
                    $emitter_index = (int) $emitter_index;
                    if (!isset($scripts[$emitter_index]) || !is_array($scripts[$emitter_index])) {
                        continue;
                    }
                    $emitter_script = $scripts[$emitter_index];
                    if ($this->runtime_js_scan_same_inventory_script($listener_script, $emitter_script)) {
                        continue;
                    }
                    $emitter_strategy = $this->runtime_js_scan_script_effective_strategy($emitter_script);
                    $emitter_timing = strtolower(trim((string) ($emitter_timings[$event][$emitter_index] ?? 'unknown')));
                    $preferred_target = $this->runtime_js_scan_lifecycle_preferred_target($listener_strategy, $emitter_strategy, $prefer_handle, $emitter_timing);
                    if ('' === $preferred_target) {
                        continue;
                    }
                    $suggestion = $prefer_handle ? $this->runtime_js_scan_strong_suggestion_for_script($listener_script) : $this->runtime_js_scan_dependency_suggestion_for_script($listener_script);
                    if ('' === $suggestion) {
                        continue;
                    }
                    $listener_name = sanitize_text_field((string) (!empty($listener_script['handle']) ? $listener_script['handle'] : (!empty($listener_script['id']) ? $listener_script['id'] : basename((string) ($listener_script['src'] ?? 'listener')))));
                    $emitter_name = sanitize_text_field((string) (!empty($emitter_script['handle']) ? $emitter_script['handle'] : (!empty($emitter_script['id']) ? $emitter_script['id'] : basename((string) ($emitter_script['src'] ?? 'emitter')))));
                    $this->runtime_js_scan_add_suggestion(
                        $suggestions,
                        $seen,
                        $suggestion,
                        'lifecycle listener ' . $event,
                        (string) ($listener_script['src'] ?? ''),
                        'Static JS scan found a lifecycle listener/emitter execution-order conflict for event "' . $event . '".',
                        'Local JS inspection found "' . $listener_name . '" registering lifecycle event "' . $event . '" with strategy ' . $listener_strategy . ', while "' . $emitter_name . '" emits the same event with strategy ' . $emitter_strategy . ($prefer_handle ? ' and timing ' . str_replace('_', ' ', $emitter_timing) : '') . '. The listener can miss a one-time initialization event without producing a console error. Use the least-invasive earlier strategy proposed by this finding and rescan.',
                        $exclusions,
                        'recommended',
                        $preferred_target
                    );
                    $added = true;
                    break;
                }
                if (count($suggestions) >= 80) {
                    return true;
                }
            }
        }
        return $added;
    }

    private function runtime_js_scan_add_lifecycle_dependency_risk_suggestions(&$suggestions, &$seen, array $scripts, array $exclusions, $prefer_handle = false)
    {
        $listeners = array();
        $emitters = array();
        $emitter_timings = array();
        $scanned = 0;
        foreach ($scripts as $index => $script) {
            if (!is_array($script) || empty($script['src']) || $this->runtime_js_scan_is_ultracache_runtime_helper_source((string) $script['src'])) {
                continue;
            }
            $content = $this->runtime_js_scan_script_content($script);
            if ('' === $content) {
                continue;
            }
            $scanned++;
            foreach ($this->runtime_js_scan_extract_lifecycle_events($content, 'listen') as $event) {
                $listeners[$event][] = (int) $index;
            }
            $script_emitter_timings = $this->runtime_js_scan_extract_lifecycle_emitter_evidence($content);
            foreach (array_keys($script_emitter_timings) as $event) {
                $emitters[$event][] = (int) $index;
                $emitter_timings[$event][(int) $index] = (string) ($script_emitter_timings[$event] ?? 'unknown');
            }
            if ($scanned >= 80) {
                break;
            }
        }

        return $this->runtime_js_scan_add_lifecycle_dependency_risk_suggestions_from_evidence(
            $suggestions,
            $seen,
            $scripts,
            $exclusions,
            $listeners,
            $emitters,
            $prefer_handle,
            $emitter_timings
        );
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
            if (!$is_targeted_local_asset && $has_path_context) {
                $parts = array_values(array_filter(explode('/', trim($suggested_lc, '/')), 'strlen'));
                $owner_slug = !empty($parts) ? sanitize_key((string) $parts[0]) : '';
                if ('' !== $owner_slug && function_exists('is_multisite') && in_array($owner_slug, $this->runtime_js_scan_active_plugin_slugs(), true)) {
                    $is_targeted_local_asset = true;
                } elseif ('' !== $owner_slug) {
                    foreach ($this->runtime_js_scan_theme_stage_roots() as $theme_root) {
                        if (!empty($theme_root['slug']) && sanitize_key((string) $theme_root['slug']) === $owner_slug) {
                            $is_targeted_local_asset = true;
                            break;
                        }
                    }
                }
            }
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
        $symbol_lc_for_source = strtolower(trim((string) $symbol));
        $is_dependency_analysis = 0 === strpos($symbol_lc_for_source, 'declared dependency ')
            || 0 === strpos($symbol_lc_for_source, 'lifecycle listener ');
        $safeguards = $this->runtime_js_scan_normalize_safeguard_lists($exclusions);
        $already_excluded = $this->runtime_js_scan_exclusion_already_matches($suggested_exclusion, $safeguards['fallback']);
        $already_force_deferred = !$already_excluded && $this->runtime_js_scan_exclusion_already_matches($suggested_exclusion, $safeguards['force']);
        $runtime_state = $this->runtime_js_scan_runtime_state_for_candidate($suggested_exclusion);
        $direct_runtime_failure = $this->runtime_js_scan_is_explicit_runtime_error($message)
            && $this->runtime_js_scan_candidate_matches_error_source($suggested_exclusion, $source);
        $still_failing_while_listed = $already_excluded && $direct_runtime_failure;
        $listed_but_ineffective = $still_failing_while_listed && !empty($runtime_state['matched'])
            && (!empty($runtime_state['delayed']) || !empty($runtime_state['deferred']) || !empty($runtime_state['async']));
        $appendable = !$ignored && !$not_fixable && !$already_excluded;
        $key = strtolower($suggested_exclusion . '|' . (string) $source . '|' . (string) $symbol);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $fallback_recommended = ($already_force_deferred && !$already_excluded) || ('exclusion' === $preferred_target && !$already_excluded);
        if ($still_failing_while_listed) {
            $category = $listed_but_ineffective ? 'listed-but-ineffective' : 'listed-but-still-failing';
            $category_label = $listed_but_ineffective ? 'Listed but still optimized on scanned page' : 'Listed but runtime error persists';
            if ($listed_but_ineffective) {
                $reason .= ' This exact script is already covered by Do Not Defer or Delay, but the scanned page still shows an optimized execution strategy (' . implode(', ', (array) $runtime_state['strategies']) . '). Keep the exclusion visible: purge cache and rescan; if the optimized state remains, the safeguard is not taking effect on the final HTML.';
            } else {
                $reason .= ' This exact script is already covered by Do Not Defer or Delay and the scanned page no longer shows it delayed/deferred, but the browser error still originates from the same script. Do not suppress this finding: inspect its providers, declared dependencies and lifecycle dependencies for the next minimal exclusion.';
            }
        } else {
            $category = $ignored ? 'ignored' : ($not_fixable ? 'not-fixable' : ($already_excluded ? 'already-listed' : ($fallback_recommended ? 'fallback-candidate' : ($is_dependency_analysis ? 'dependency-risk' : 'appendable-fix'))));
            $category_label = $ignored ? 'Ignored' : ($not_fixable ? 'Not fixable by exclusion' : ($already_excluded ? 'Already listed in Do Not Defer or Delay' : ($fallback_recommended ? 'Do Not Defer or Delay candidate' : ($is_dependency_analysis ? 'Dependency risk' : 'Appendable fixes'))));
        }
        $suggestions[] = array(
            'symbol'             => (string) $symbol,
            'source'             => $is_dependency_analysis ? 'page-dependency-analysis' : 'browser-runtime-error',
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
            'stillFailingWhileListed' => $still_failing_while_listed,
            'listedButIneffective' => $listed_but_ineffective,
            'runtimeMatchCount'  => (int) ($runtime_state['matchCount'] ?? 0),
            'runtimeStrategies'  => array_values((array) ($runtime_state['strategies'] ?? array())),
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
            'wp.element',
            'wp.data',
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
        } elseif (false !== strpos($symbol, 'wp.element')) {
            $symbol = 'wp.element';
        } elseif (false !== strpos($symbol, 'wp.data')) {
            $symbol = 'wp.data';
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
        if ('wp.element' === $symbol) {
            return false !== strpos($path, 'dist/element.js') || false !== strpos($path, 'dist/element.min.js') || false !== strpos($path, 'wp-element-js');
        }
        if ('wp.data' === $symbol) {
            return false !== strpos($path, 'dist/data.js') || false !== strpos($path, 'dist/data.min.js') || false !== strpos($path, 'wp-data-js');
        }
        if ('wp.template' === $symbol) {
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
        if ('wp.element' === $symbol) {
            return array('wp-element');
        }
        if ('wp.data' === $symbol) {
            return array('wp-data');
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
        } elseif ('wp.template' === $symbol) {
            $relative = 'js/wp-util.min.js';
        } elseif ('wp.i18n' === $symbol) {
            $relative = 'js/dist/i18n.min.js';
        } elseif ('wp.hooks' === $symbol) {
            $relative = 'js/dist/hooks.min.js';
        } elseif ('wp.apifetch' === $symbol) {
            $relative = 'js/dist/api-fetch.min.js';
        } elseif ('wp.domready' === $symbol) {
            $relative = 'js/dist/dom-ready.min.js';
        } elseif ('wp.element' === $symbol) {
            $relative = 'js/dist/element.min.js';
        } elseif ('wp.data' === $symbol) {
            $relative = 'js/dist/data.min.js';
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
        if (preg_match('/(?:ReferenceError:\s*)?wp\.element\s+is\s+not\s+defined/i', $text)) {
            $symbols['wp.element'] = 'wp.element';
        }
        if (preg_match('/(?:ReferenceError:\s*)?wp\.data\s+is\s+not\s+defined/i', $text)) {
            $symbols['wp.data'] = 'wp.data';
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

        // Exact provider resolution is intentionally allowed to return a
        // WordPress core path. Generic stack-frame helpers reject wp-includes
        // and generic basenames, but this path is reached only after the
        // missing package and provider identity have matched explicitly.
        $path = (string) wp_parse_url($source, PHP_URL_PATH);
        if ('' === $path) {
            $path = preg_replace('/[?#].*$/', '', $source);
        }
        $segments = array_values(array_filter(explode('/', strtolower(trim((string) $path, '/'))), 'strlen'));
        if (empty($segments)) {
            return '';
        }

        return sanitize_text_field(implode('/', array_slice($segments, -1 * min(5, count($segments)))));
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
            if ($is_handle_like && empty($item['stillFailingWhileListed'])) {
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
            if ('' === $key) {
                continue;
            }
            if (isset($seen_final[$key])) {
                $existing_index = (int) $seen_final[$key];
                $existing = isset($out[$existing_index]) && is_array($out[$existing_index]) ? $out[$existing_index] : array();
                if (!empty($item['stillFailingWhileListed']) && empty($existing['stillFailingWhileListed'])) {
                    $out[$existing_index] = $item;
                }
                continue;
            }
            $seen_final[$key] = count($out);
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

    private function runtime_js_scan_jquery_file_defines_method($content, $method, $identity = '')
    {
        $method = trim((string) $method);
        $content = (string) $content;
        if ('' === $method) {
            return false;
        }

        if ($this->runtime_js_scan_jquery_provider_identity_matches_method((string) $identity, $method)) {
            return true;
        }
        if ('' === $content) {
            return false;
        }

        $method_regex = preg_quote($method, '/');
        $jquery_alias = '(?:jQuery|\\$|[A-Za-z_$][A-Za-z0-9_$]*)';
        if (preg_match('/' . $jquery_alias . '\\s*\\.\\s*fn\\s*(?:\\.\\s*' . $method_regex . '|\\[\\s*["\\\']' . $method_regex . '["\\\']\\s*\\])\\s*=/i', $content)) {
            return true;
        }
        if (preg_match('/' . $jquery_alias . '\\s*\\.\\s*fn\\s*\\.\\s*extend\\s*\\(\\s*\\{/i', $content)
            && preg_match('/(?:^|[,{}])\\s*["\\\']?' . $method_regex . '["\\\']?\\s*:/i', $content)) {
            return true;
        }

        return false;
    }

    private function runtime_js_scan_jquery_file_uses_method($content, $method)
    {
        $method = trim((string) $method);
        $content = (string) $content;
        if ('' === $method || '' === $content) {
            return false;
        }

        $method_regex = preg_quote($method, '/');
        if (preg_match('/\\.\\s*' . $method_regex . '\\s*\\(/i', $content)) {
            return true;
        }
        if (preg_match('/\\[\\s*["\\\']' . $method_regex . '["\\\']\\s*\\]\\s*\\(/i', $content)) {
            return true;
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
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $src = isset($script['src']) ? (string) $script['src'] : '';
            $id = isset($script['id']) ? (string) $script['id'] : '';
            $content = $this->runtime_js_scan_script_content($script);
            if (!$this->runtime_js_scan_jquery_file_defines_method($content, $method, $src . ' ' . $id)) {
                continue;
            }

            $key = strtolower($src . '|' . $id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $providers[] = array('src' => $src, 'id' => $id, 'origin' => 'page-inventory');
            if (count($providers) >= 8) {
                break;
            }
        }

        return $providers;
    }

    private function runtime_js_scan_jquery_filesystem_roots($source, $message, $detail)
    {
        $roots = array();
        $seen = array();
        $push = static function ($kind, array $root, $targeted = false) use (&$roots, &$seen) {
            $dir = isset($root['dir']) ? (string) $root['dir'] : '';
            $uri = isset($root['uri']) ? (string) $root['uri'] : '';
            $slug = isset($root['slug']) ? sanitize_key((string) $root['slug']) : '';
            if ('' === $dir || '' === $uri || '' === $slug) {
                return;
            }
            $key = strtolower((string) $kind . '|' . $slug . '|' . $dir);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $root['kind'] = (string) $kind;
            $root['targeted'] = (bool) $targeted;
            $roots[] = $root;
        };

        foreach ($this->runtime_js_scan_plugin_stage_roots($source, $message, $detail) as $root) {
            $stage = isset($root['stage']) ? strtolower((string) $root['stage']) : '';
            $push('plugin', $root, false !== strpos($stage, 'targeted'));
        }

        $owner_slugs = array();
        foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
            $owner = $this->runtime_js_scan_owner_group_from_source($candidate);
            if (!empty($owner['kind']) && 'theme' === $owner['kind'] && !empty($owner['slug'])) {
                $owner_slugs[sanitize_key((string) $owner['slug'])] = true;
            }
        }
        $theme_roots = $this->runtime_js_scan_theme_stage_roots();
        foreach ($theme_roots as $root) {
            $slug = isset($root['slug']) ? sanitize_key((string) $root['slug']) : '';
            if ('' !== $slug && isset($owner_slugs[$slug])) {
                $push('theme', $root, true);
            }
        }

        // The direct consumer owner is searched first. If its provider is not
        // there, continue through the bounded active-plugin/theme inventory.
        foreach ($this->runtime_js_scan_plugin_stage_roots('', '', '') as $root) {
            $push('plugin', $root);
        }
        foreach ($theme_roots as $root) {
            $push('theme', $root);
        }

        return $roots;
    }

    private function runtime_js_scan_find_jquery_plugin_filesystem_context($method, $source, $message, $detail, $provider_already_found = false)
    {
        static $cache = array();

        $method = trim((string) $method);
        if ('' === $method) {
            return array('providers' => array(), 'consumers' => array());
        }

        $cache_key = md5(strtolower($method) . '|' . (string) $source . '|' . (string) $message . '|' . (string) $detail . '|' . (!empty($provider_already_found) ? '1' : '0'));
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $context = array('providers' => array(), 'consumers' => array());
        $seen = array('providers' => array(), 'consumers' => array());
        $roots = $this->runtime_js_scan_jquery_filesystem_roots($source, $message, $detail);
        $has_targeted_roots = false;
        foreach ($roots as $candidate_root) {
            if (!empty($candidate_root['targeted'])) {
                $has_targeted_roots = true;
                break;
            }
        }
        foreach ($roots as $root) {
            $kind = isset($root['kind']) ? (string) $root['kind'] : '';
            $root_dir = isset($root['dir']) ? (string) $root['dir'] : '';
            $root_uri = isset($root['uri']) ? (string) $root['uri'] : '';
            $max_files = isset($root['max_files']) ? (int) $root['max_files'] : ('plugin' === $kind ? 35 : 80);
            $max_depth = isset($root['max_depth']) ? (int) $root['max_depth'] : ('plugin' === $kind ? 4 : 6);
            if ('' === $kind || '' === $root_dir || '' === $root_uri) {
                continue;
            }

            $files = 'plugin' === $kind
                ? $this->runtime_js_scan_plugin_stage_files($root_dir, $max_files, $max_depth)
                : $this->runtime_js_scan_theme_stage_files($root_dir, $max_files, $max_depth);

            foreach ($files as $file) {
                $content = function_exists('ultracache_guarded_asset_file_get_contents')
                    ? ultracache_guarded_asset_file_get_contents($file, 'js', 'runtime_js_jquery_dependency_scan', true)
                    : false;
                if (!is_string($content) || '' === $content) {
                    continue;
                }

                $is_provider = empty($provider_already_found) && $this->runtime_js_scan_jquery_file_defines_method($content, $method, $file);
                $scan_consumers_here = !$has_targeted_roots || !empty($root['targeted']);
                $is_consumer = $scan_consumers_here && $this->runtime_js_scan_jquery_file_uses_method($content, $method);
                if (!$is_provider && !$is_consumer) {
                    continue;
                }

                $relative = $this->runtime_js_scan_theme_stage_relative_path($file, $root_dir);
                if ('' === $relative) {
                    continue;
                }
                $url = esc_url_raw(trailingslashit($root_uri) . ltrim($relative, '/'));
                $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($url, 5);
                if ('' === $fragment) {
                    continue;
                }
                $entry = array(
                    'src'      => $url,
                    'id'       => '',
                    'fragment' => $fragment,
                    'origin'   => $kind . '-filesystem',
                );

                if ($is_provider) {
                    $key = strtolower($fragment . '|provider');
                    if (!isset($seen['providers'][$key])) {
                        $seen['providers'][$key] = true;
                        $context['providers'][] = $entry;
                    }
                }
                if ($is_consumer) {
                    $key = strtolower($fragment . '|consumer');
                    if (!isset($seen['consumers'][$key])) {
                        $seen['consumers'][$key] = true;
                        $context['consumers'][] = $entry;
                    }
                }

                if (count($context['providers']) >= 12 && count($context['consumers']) >= 12) {
                    break 2;
                }
            }
            if ((!empty($provider_already_found) && !empty($context['consumers'])) || (!empty($context['providers']) && !empty($context['consumers']))) {
                break;
            }
        }

        $context['providers'] = array_slice($context['providers'], 0, 12);
        $context['consumers'] = array_slice($context['consumers'], 0, 12);
        $cache[$cache_key] = $context;
        return $context;
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

    private function runtime_js_scan_add_jquery_plugin_dependency_suggestions(&$suggestions, &$seen, $method, $source, $message, $detail, array $exclusions, array $scripts = array(), array $filesystem_context = array())
    {
        $method = trim((string) $method);
        if ('' === $method) {
            return false;
        }

        $providers = array();
        $provider_seen = array();
        $add_provider = static function ($provider) use (&$providers, &$provider_seen) {
            if (!is_array($provider)) {
                return;
            }
            $src = isset($provider['src']) ? (string) $provider['src'] : '';
            $id = isset($provider['id']) ? (string) $provider['id'] : '';
            $fragment = isset($provider['fragment']) ? (string) $provider['fragment'] : '';
            $key = strtolower($src . '|' . $id . '|' . $fragment);
            if ('' === str_replace('|', '', $key) || isset($provider_seen[$key])) {
                return;
            }
            $provider_seen[$key] = true;
            $providers[] = $provider;
        };

        foreach ($this->runtime_js_scan_find_jquery_plugin_provider_scripts($method, $scripts) as $provider) {
            $add_provider($provider);
        }
        foreach ((array) ($filesystem_context['providers'] ?? array()) as $provider) {
            $add_provider($provider);
        }

        $consumers = array();
        $consumer_seen = array();
        $add_consumer = function ($candidate, $origin = 'runtime-stack') use (&$consumers, &$consumer_seen) {
            if (is_array($candidate)) {
                $src = isset($candidate['src']) ? (string) $candidate['src'] : '';
                $fragment = isset($candidate['fragment']) ? (string) $candidate['fragment'] : '';
                $origin = isset($candidate['origin']) ? (string) $candidate['origin'] : (string) $origin;
            } else {
                $src = (string) $candidate;
                $fragment = '';
            }
            $src = $this->runtime_js_scan_clean_console_candidate($src);
            if ('' === $fragment && '' !== $src) {
                $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($src, 5);
            }
            if ('' === $fragment) {
                return;
            }
            $key = strtolower($fragment);
            if (isset($consumer_seen[$key])) {
                return;
            }
            $consumer_seen[$key] = true;
            $consumers[] = array(
                'src'      => $src,
                'fragment' => $fragment,
                'origin'   => (string) $origin,
            );
        };

        $source_candidates = $this->runtime_js_scan_source_candidates_from_error($source, $message, $detail);
        foreach ($source_candidates as $candidate) {
            $owner = $this->runtime_js_scan_owner_group_from_source($candidate);
            if (!empty($owner)) {
                $add_consumer($candidate, 'runtime-stack');
            }
            $matches = $this->runtime_js_scan_find_scripts_by_source_hint($candidate, $scripts);
            $method_matches = array();
            foreach ($matches as $matched_script) {
                if ($this->runtime_js_scan_jquery_file_uses_method($this->runtime_js_scan_script_content($matched_script), $method)) {
                    $method_matches[] = $matched_script;
                }
            }
            if (empty($method_matches) && 1 === count($matches)) {
                $method_matches = $matches;
            }
            foreach ($method_matches as $matched_script) {
                if (!empty($matched_script['src'])) {
                    $add_consumer((string) $matched_script['src'], 'page-inventory');
                }
            }
        }

        foreach ($scripts as $script) {
            if (!is_array($script) || empty($script['src'])) {
                continue;
            }
            if ($this->runtime_js_scan_jquery_file_uses_method($this->runtime_js_scan_script_content($script), $method)) {
                $add_consumer((string) $script['src'], 'page-inventory');
            }
        }
        foreach ((array) ($filesystem_context['consumers'] ?? array()) as $consumer) {
            $add_consumer($consumer, 'filesystem');
        }

        $added = false;
        foreach ($providers as $provider) {
            $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
            $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
            $provider_fragment = isset($provider['fragment']) ? (string) $provider['fragment'] : '';
            if ('' === $provider_fragment) {
                $provider_fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($provider_src, 5);
            }
            if ('' !== $provider_fragment) {
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $provider_fragment,
                    'jQuery plugin provider script',
                    $provider_src,
                    $message,
                    'Runtime Scan found the exact active plugin/theme script that registers jQuery.fn.' . sanitize_text_field($method) . '. Keep this provider available before the direct consumer.',
                    $exclusions,
                    'recommended'
                );
                $added = true;
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
                $added = true;
            }
        }

        $provider_found = !empty($providers);
        foreach (array_slice($consumers, 0, 12) as $consumer) {
            $consumer_source = isset($consumer['src']) ? (string) $consumer['src'] : '';
            $consumer_fragment = isset($consumer['fragment']) ? (string) $consumer['fragment'] : '';
            if ('' === $consumer_fragment) {
                continue;
            }
            $reason = $provider_found
                ? 'This exact active plugin/theme script calls .' . sanitize_text_field($method) . '(). Keep it in the same execution strategy as the resolved jQuery plugin provider.'
                : 'This exact active plugin/theme script calls .' . sanitize_text_field($method) . '(), but Runtime Scan did not find any active plugin/theme file that registers jQuery.fn.' . sanitize_text_field($method) . '. Keep this consumer unchanged and review whether its provider was not enqueued or was removed by another plugin.';
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $consumer_fragment,
                $provider_found ? 'jQuery plugin direct consumer' : 'jQuery plugin consumer — provider not found',
                $consumer_source,
                $message,
                $reason,
                $exclusions,
                'recommended'
            );
            $added = true;
        }

        return $added;
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

    private function runtime_js_scan_aggregate_strong_suggestions(array $suggestions)
    {
        $out = array();
        $indexes = array();

        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion) || empty($suggestion['suggestedExclusion'])) {
                continue;
            }
            $key = $this->runtime_js_scan_canonical_suggestion_identity((string) $suggestion['suggestedExclusion']);
            if ('' === $key) {
                continue;
            }

            $symbol = strtolower(trim((string) ($suggestion['symbol'] ?? '')));
            $type = 0 === strpos($symbol, 'lifecycle listener ') ? 'lifecycle' : (0 === strpos($symbol, 'declared dependency ') ? 'declared-dependency' : '');
            if ('' === $type) {
                continue;
            }
            $evidence_key = $type . '|' . $symbol . '|' . strtolower(trim((string) ($suggestion['definingScriptUrl'] ?? '')));
            $evidence = array(
                'type' => $type,
                'symbol' => (string) ($suggestion['symbol'] ?? ''),
                'source' => (string) ($suggestion['definingScriptUrl'] ?? ''),
                'preferredTarget' => (string) ($suggestion['preferredTarget'] ?? ''),
            );

            if (!isset($indexes[$key])) {
                $suggestion['strongEvidence'] = array($evidence);
                $suggestion['strongEvidenceCount'] = 1;
                $suggestion['strongEvidenceTypes'] = array($type);
                $suggestion['strongPrimaryEvidence'] = $type;
                $suggestion['_strongEvidenceKeys'] = array($evidence_key => true);
                $indexes[$key] = count($out);
                $out[] = $suggestion;
                continue;
            }

            $index = (int) $indexes[$key];
            if (!isset($out[$index]) || !is_array($out[$index])) {
                continue;
            }
            if (!isset($out[$index]['_strongEvidenceKeys']) || !is_array($out[$index]['_strongEvidenceKeys'])) {
                $out[$index]['_strongEvidenceKeys'] = array();
            }
            if (!isset($out[$index]['_strongEvidenceKeys'][$evidence_key])) {
                $out[$index]['_strongEvidenceKeys'][$evidence_key] = true;
                $out[$index]['strongEvidence'][] = $evidence;
            }
            $out[$index]['strongEvidenceCount'] = count((array) ($out[$index]['strongEvidence'] ?? array()));
            $types = array();
            foreach ((array) ($out[$index]['strongEvidence'] ?? array()) as $entry) {
                $entry_type = sanitize_key((string) ($entry['type'] ?? ''));
                if ('' !== $entry_type) {
                    $types[$entry_type] = true;
                }
            }
            $out[$index]['strongEvidenceTypes'] = array_keys($types);

            if ('exclusion' === (string) ($suggestion['preferredTarget'] ?? '') && 'exclusion' !== (string) ($out[$index]['preferredTarget'] ?? '')) {
                $out[$index]['preferredTarget'] = 'exclusion';
                $out[$index]['fallbackRecommended'] = empty($out[$index]['alreadyExcluded']);
            }
        }

        foreach ($out as &$suggestion) {
            unset($suggestion['_strongEvidenceKeys']);
            $count = max(1, (int) ($suggestion['strongEvidenceCount'] ?? 1));
            if ($count > 1) {
                $suggestion['reason'] = rtrim((string) ($suggestion['reason'] ?? '')) . ' ' . $count . ' independent execution-order signals point to this same script; UltraCache combines them into one Strong Suggestion.';
            }
        }
        unset($suggestion);

        usort($out, function ($left, $right) {
            $left_safe = !empty($left['alreadyExcluded']) || !empty($left['alreadyForceDeferred']);
            $right_safe = !empty($right['alreadyExcluded']) || !empty($right['alreadyForceDeferred']);
            if ($left_safe !== $right_safe) {
                return $left_safe ? 1 : -1;
            }
            $left_count = (int) ($left['strongEvidenceCount'] ?? 1);
            $right_count = (int) ($right['strongEvidenceCount'] ?? 1);
            if ($left_count !== $right_count) {
                return $right_count <=> $left_count;
            }
            $left_primary = (string) ($left['strongPrimaryEvidence'] ?? '');
            $right_primary = (string) ($right['strongPrimaryEvidence'] ?? '');
            $left_priority = 'lifecycle' === $left_primary ? 2 : 1;
            $right_priority = 'lifecycle' === $right_primary ? 2 : 1;
            if ($left_priority !== $right_priority) {
                return $right_priority <=> $left_priority;
            }
            return strcmp((string) ($left['suggestedExclusion'] ?? ''), (string) ($right['suggestedExclusion'] ?? ''));
        });

        return $out;
    }

    private function runtime_js_scan_finalize_strong_silent_dependency_result(array $suggestions)
    {
        $strong = array();
        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion) || empty($suggestion['suggestedExclusion'])) {
                continue;
            }
            $symbol = strtolower(trim((string) ($suggestion['symbol'] ?? '')));
            $is_declared = 0 === strpos($symbol, 'declared dependency ');
            $is_lifecycle = 0 === strpos($symbol, 'lifecycle listener ');
            if (!$is_declared && !$is_lifecycle) {
                continue;
            }
            if ($is_lifecycle) {
                $event = trim(substr($symbol, strlen('lifecycle listener ')));
                if (!$this->runtime_js_scan_is_strong_lifecycle_event($event)) {
                    continue;
                }
            }
            $suggestion['source'] = 'html-strong-dependency-analysis';
            $suggestion['category'] = 'strong-suggestion';
            $suggestion['categoryLabel'] = 'Strong suggestion';
            $suggestion['strongSuggestion'] = true;
            $strong[] = $suggestion;
        }

        $strong = $this->runtime_js_scan_aggregate_strong_suggestions($strong);
        $strong = $this->runtime_js_scan_finalize_suggestions($strong);
        if (count($strong) > 8) {
            $strong = array_slice($strong, 0, 8);
        }

        $missing = 0;
        $already_safeguarded = 0;
        foreach ($strong as $suggestion) {
            if (!empty($suggestion['alreadyExcluded']) || !empty($suggestion['alreadyForceDeferred'])) {
                $already_safeguarded++;
            } else {
                $missing++;
            }
        }

        $this->runtime_js_scan_current_scripts = array();

        return array(
            'available'                 => true,
            'source'                    => 'html-strong-dependency-analysis',
            'suggestion_count'          => count($strong),
            'missing_count'             => (int) $missing,
            'already_safeguarded_count' => (int) $already_safeguarded,
            'suggestions'               => $strong,
        );
    }

    private function build_runtime_js_strong_silent_dependency_suggestions_from_evidence(array $scripts, array $listeners, array $emitters, array $emitter_timings = array())
    {
        // The registry caller already supplies one normalized inventory. Do not
        // normalize again here: persisted inline bodies are intentionally omitted
        // from resumable state, and a second normalization could compact numeric
        // indexes before lifecycle evidence is correlated.
        $this->runtime_js_scan_current_scripts = $scripts;
        $exclusions = $this->get_runtime_js_scan_current_exclusions();
        $suggestions = array();
        $seen = array();

        $this->runtime_js_scan_add_lifecycle_dependency_risk_suggestions_from_evidence($suggestions, $seen, $scripts, $exclusions, $listeners, $emitters, true, $emitter_timings);
        $this->runtime_js_scan_add_declared_dependency_risk_suggestions($suggestions, $seen, $scripts, $exclusions, true);

        $result = $this->runtime_js_scan_finalize_strong_silent_dependency_result($suggestions);
        $this->runtime_js_scan_current_scripts = array();
        return $result;
    }

    private function build_runtime_js_strong_silent_dependency_suggestions_from_registry(array $scripts, array $registry)
    {
        $scripts = $this->runtime_js_scan_normalize_script_inventory($scripts);
        $identity_indexes = array();
        foreach ($scripts as $script_index => $script) {
            if (!is_array($script)) {
                continue;
            }
            $identity = $this->runtime_js_scan_script_evidence_identity($script);
            if ('' !== $identity && !isset($identity_indexes[$identity])) {
                $identity_indexes[$identity] = (int) $script_index;
            }
        }

        $listeners = array();
        $emitters = array();
        $emitter_timings = array();

        foreach ($registry as $script_identity => $evidence) {
            $script_identity = sanitize_text_field((string) $script_identity);
            if ('' === $script_identity || !isset($identity_indexes[$script_identity]) || !is_array($evidence)) {
                continue;
            }
            $script_index = (int) $identity_indexes[$script_identity];
            foreach ((array) ($evidence['listeners'] ?? array()) as $event) {
                $event = trim(sanitize_text_field((string) $event));
                if ('' === $event) {
                    continue;
                }
                if (!isset($listeners[$event])) {
                    $listeners[$event] = array();
                }
                if (!in_array($script_index, $listeners[$event], true)) {
                    $listeners[$event][] = $script_index;
                }
            }
            foreach ((array) ($evidence['emitters'] ?? array()) as $event) {
                $event = trim(sanitize_text_field((string) $event));
                if ('' === $event) {
                    continue;
                }
                if (!isset($emitters[$event])) {
                    $emitters[$event] = array();
                }
                if (!in_array($script_index, $emitters[$event], true)) {
                    $emitters[$event][] = $script_index;
                }
                $timing_map = isset($evidence['emitterTimings']) && is_array($evidence['emitterTimings']) ? $evidence['emitterTimings'] : array();
                $emitter_timings[$event][$script_index] = strtolower(trim((string) ($timing_map[$event] ?? 'unknown')));
            }
        }

        return $this->build_runtime_js_strong_silent_dependency_suggestions_from_evidence($scripts, $listeners, $emitters, $emitter_timings);
    }

    private function runtime_js_scan_script_evidence_identity(array $script)
    {
        $handle = sanitize_text_field(substr((string) ($script['handle'] ?? ''), 0, 160));
        $src = $this->runtime_js_scan_sanitize_source((string) ($script['src'] ?? ''));
        $id = sanitize_text_field(substr((string) ($script['id'] ?? ''), 0, 160));
        if ('' === $handle && '' === $src && '' === $id) {
            return '';
        }

        return 'js:' . hash('sha256', $handle . "\n" . $src . "\n" . $id);
    }

    private function runtime_js_scan_lifecycle_analysis_cache_parser_version()
    {
        return '2';
    }

    private function runtime_js_scan_lifecycle_analysis_cache_descriptor(array $script)
    {
        $src = trim((string) ($script['src'] ?? ''));
        if ('' === $src) {
            return array();
        }

        $path = $this->runtime_js_scan_local_file_path_from_script_src($src);
        if ('' === $path || !is_file($path)) {
            return array();
        }

        $normalized_path = function_exists('wp_normalize_path') ? wp_normalize_path($path) : str_replace('\\', '/', $path);
        $mtime = function_exists('ultracache_safe_filemtime')
            ? ultracache_safe_filemtime($path, 'runtime_js_lifecycle_cache_mtime')
            : @filemtime($path);
        $size = function_exists('ultracache_safe_filesize')
            ? ultracache_safe_filesize($path, 'runtime_js_lifecycle_cache_size')
            : @filesize($path);
        if (false === $mtime || false === $size) {
            return array();
        }

        $parser_version = $this->runtime_js_scan_lifecycle_analysis_cache_parser_version();
        $path_key = hash('sha256', (string) $normalized_path);
        $fingerprint = hash('sha256', implode('|', array(
            $parser_version,
            (string) $normalized_path,
            (string) max(0, (int) $mtime),
            (string) max(0, (int) $size),
        )));

        return array(
            'state_name' => 'ultracache_state:js-analysis.' . $path_key,
            'fingerprint' => $fingerprint,
            'mtime' => max(0, (int) $mtime),
            'size' => max(0, (int) $size),
            'parser_version' => $parser_version,
        );
    }

    private function runtime_js_scan_normalize_cached_lifecycle_events($events)
    {
        $normalized = array();
        foreach (array_slice((array) $events, 0, 96) as $event) {
            $event = trim(sanitize_text_field((string) $event));
            if ('' === $event || isset($normalized[$event])) {
                continue;
            }
            $normalized[$event] = true;
        }
        return array_keys($normalized);
    }

    private function runtime_js_scan_normalize_cached_emitter_timings($timings, array $events = array())
    {
        $allowed = array('immediate', 'dom_ready', 'window_load', 'callback', 'unknown', 'mixed');
        $event_lookup = array_fill_keys($events, true);
        $out = array();
        foreach ((array) $timings as $event => $timing) {
            $event = trim(sanitize_text_field((string) $event));
            $timing = strtolower(trim(sanitize_text_field((string) $timing)));
            if ('' === $event || (!empty($event_lookup) && !isset($event_lookup[$event]))) {
                continue;
            }
            $out[$event] = in_array($timing, $allowed, true) ? $timing : 'unknown';
        }
        foreach ($events as $event) {
            if (!isset($out[$event])) {
                $out[$event] = 'unknown';
            }
        }
        return $out;
    }

    private function runtime_js_scan_get_cached_lifecycle_analysis(array $script)
    {
        if (!function_exists('ultracache_get_state_record')) {
            return array('hit' => false);
        }

        $descriptor = $this->runtime_js_scan_lifecycle_analysis_cache_descriptor($script);
        if (empty($descriptor['state_name']) || empty($descriptor['fingerprint'])) {
            return array('hit' => false);
        }

        $record = ultracache_get_state_record((string) $descriptor['state_name']);
        $payload = isset($record['payload']) && is_array($record['payload']) ? $record['payload'] : array();
        if (empty($payload) || !isset($payload['fingerprint']) || !hash_equals((string) $descriptor['fingerprint'], (string) $payload['fingerprint'])) {
            return array('hit' => false, 'descriptor' => $descriptor);
        }

        if ((string) ($payload['parserVersion'] ?? '') !== (string) $descriptor['parser_version']) {
            return array('hit' => false, 'descriptor' => $descriptor);
        }

        $emitters = $this->runtime_js_scan_normalize_cached_lifecycle_events($payload['emitters'] ?? array());
        return array(
            'hit' => true,
            'descriptor' => $descriptor,
            'listeners' => $this->runtime_js_scan_normalize_cached_lifecycle_events($payload['listeners'] ?? array()),
            'emitters' => $emitters,
            'emitterTimings' => $this->runtime_js_scan_normalize_cached_emitter_timings($payload['emitterTimings'] ?? array(), $emitters),
        );
    }

    private function runtime_js_scan_store_cached_lifecycle_analysis(array $descriptor, array $listeners, array $emitters, array $emitter_timings = array())
    {
        if (empty($descriptor['state_name']) || empty($descriptor['fingerprint']) || !function_exists('ultracache_mutate_state_record')) {
            return false;
        }

        $payload = array(
            'fingerprint' => (string) $descriptor['fingerprint'],
            'parserVersion' => (string) ($descriptor['parser_version'] ?? ''),
            'mtime' => max(0, (int) ($descriptor['mtime'] ?? 0)),
            'size' => max(0, (int) ($descriptor['size'] ?? 0)),
            'listeners' => $this->runtime_js_scan_normalize_cached_lifecycle_events($listeners),
            'emitters' => $this->runtime_js_scan_normalize_cached_lifecycle_events($emitters),
            'emitterTimings' => $this->runtime_js_scan_normalize_cached_emitter_timings($emitter_timings, $this->runtime_js_scan_normalize_cached_lifecycle_events($emitters)),
        );

        $result = ultracache_mutate_state_record(
            (string) $descriptor['state_name'],
            static function () use ($payload) {
                return $payload;
            },
            3,
            $payload
        );

        return !empty($result['success']);
    }

    private function runtime_js_scan_local_lifecycle_analysis_indexes(array $scripts)
    {
        $indexes = array();
        foreach ($scripts as $index => $script) {
            if (!is_array($script) || empty($script['src']) || $this->runtime_js_scan_is_ultracache_runtime_helper_source((string) $script['src'])) {
                continue;
            }
            if ('' === $this->runtime_js_scan_local_file_path_from_script_src((string) $script['src'])) {
                continue;
            }
            $indexes[] = (int) $index;
        }
        return $indexes;
    }

    private function runtime_js_scan_analyze_lifecycle_batch(array $scripts, array $analysis_indexes, $cursor = 0, $max_files = 10, $time_budget_seconds = 1.8)
    {
        $cursor = max(0, (int) $cursor);
        $max_files = max(1, min(12, (int) $max_files));
        $time_budget_seconds = max(0.25, min(3.0, (float) $time_budget_seconds));
        $started = microtime(true);
        $evidence_registry = array();
        $processed = 0;
        $content_scanned = 0;
        $cache_hits = 0;
        $cache_misses = 0;
        $cache_writes = 0;
        $total = count($analysis_indexes);

        while ($cursor < $total && $processed < $max_files) {
            if ($processed > 0 && (microtime(true) - $started) >= $time_budget_seconds) {
                break;
            }

            $script_index = (int) $analysis_indexes[$cursor];
            $cursor++;
            $processed++;
            if (!isset($scripts[$script_index]) || !is_array($scripts[$script_index])) {
                continue;
            }

            $cached = $this->runtime_js_scan_get_cached_lifecycle_analysis($scripts[$script_index]);
            if (!empty($cached['hit'])) {
                $cache_hits++;
                $script_identity = $this->runtime_js_scan_script_evidence_identity($scripts[$script_index]);
                if ('' !== $script_identity) {
                    $evidence_registry[$script_identity] = array(
                        'listeners' => array_values((array) ($cached['listeners'] ?? array())),
                        'emitters'  => array_values((array) ($cached['emitters'] ?? array())),
                        'emitterTimings' => (array) ($cached['emitterTimings'] ?? array()),
                        'source'    => 'cache',
                    );
                }
                continue;
            }

            $cache_misses++;
            $content = $this->runtime_js_scan_script_content($scripts[$script_index]);
            if ('' === $content) {
                continue;
            }
            $content_scanned++;

            $script_listeners = $this->runtime_js_scan_extract_lifecycle_events($content, 'listen');
            $script_emitter_timings = $this->runtime_js_scan_extract_lifecycle_emitter_evidence($content);
            $script_emitters = array_keys($script_emitter_timings);
            $script_identity = $this->runtime_js_scan_script_evidence_identity($scripts[$script_index]);
            if ('' !== $script_identity) {
                $evidence_registry[$script_identity] = array(
                    'listeners' => array_values($script_listeners),
                    'emitters'  => array_values($script_emitters),
                    'emitterTimings' => $script_emitter_timings,
                    'source'    => 'scan',
                );
            }

            $descriptor = isset($cached['descriptor']) && is_array($cached['descriptor'])
                ? $cached['descriptor']
                : $this->runtime_js_scan_lifecycle_analysis_cache_descriptor($scripts[$script_index]);
            if ($this->runtime_js_scan_store_cached_lifecycle_analysis($descriptor, $script_listeners, $script_emitters, $script_emitter_timings)) {
                $cache_writes++;
            }
        }

        return array(
            'next_cursor'     => $cursor,
            'processed'       => $processed,
            'content_scanned' => $content_scanned,
            'cache_hits'      => $cache_hits,
            'cache_misses'    => $cache_misses,
            'cache_writes'    => $cache_writes,
            'evidence'        => $evidence_registry,
            'done'            => $cursor >= $total,
        );
    }

    private function build_runtime_js_strong_silent_dependency_suggestions(array $scripts = array())
    {
        $scripts = $this->runtime_js_scan_normalize_script_inventory($scripts);
        $this->runtime_js_scan_current_scripts = $scripts;
        $exclusions = $this->get_runtime_js_scan_current_exclusions();
        $suggestions = array();
        $seen = array();

        $this->runtime_js_scan_add_lifecycle_dependency_risk_suggestions($suggestions, $seen, $scripts, $exclusions, true);
        $this->runtime_js_scan_add_declared_dependency_risk_suggestions($suggestions, $seen, $scripts, $exclusions, true);

        $result = $this->runtime_js_scan_finalize_strong_silent_dependency_result($suggestions);
        $this->runtime_js_scan_current_scripts = array();
        return $result;
    }

    private function build_runtime_js_scan_suggestions(array $errors, array $scripts = array())
    {
        $scripts = $this->runtime_js_scan_normalize_script_inventory($scripts);
        $this->runtime_js_scan_current_scripts = $scripts;
        $exclusions = $this->get_runtime_js_scan_current_exclusions();
        $suggestions = array();
        $seen = array();

        // Dependency analysis is page-scoped and can find silent execution-order
        // risks even when the pasted/browser console contains no matching error.
        $this->runtime_js_scan_add_declared_dependency_risk_suggestions($suggestions, $seen, $scripts, $exclusions);
        $this->runtime_js_scan_add_lifecycle_dependency_risk_suggestions($suggestions, $seen, $scripts, $exclusions);

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

            if ($is_explicit_runtime_error) {
                $this->runtime_js_scan_add_persistent_exact_error_source_suggestion(
                    $suggestions,
                    $seen,
                    $source,
                    $message,
                    $detail,
                    $exclusions
                );
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
                $page_providers = $this->runtime_js_scan_find_jquery_plugin_provider_scripts($method, $scripts);
                $filesystem_context = $this->runtime_js_scan_find_jquery_plugin_filesystem_context($method, $source, $message, $detail, !empty($page_providers));
                if ($this->runtime_js_scan_add_jquery_plugin_dependency_suggestions(
                    $suggestions,
                    $seen,
                    $method,
                    $source,
                    $message,
                    $detail,
                    $exclusions,
                    $scripts,
                    $filesystem_context
                )) {
                    $jquery_plugin_provider_added = true;
                }
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

        $persistent_listed_failures = 0;
        $dependency_risks = 0;
        foreach ($suggestions as $suggestion) {
            if (!empty($suggestion['stillFailingWhileListed'])) {
                $persistent_listed_failures++;
            }
            $symbol = strtolower((string) ($suggestion['symbol'] ?? ''));
            if (0 === strpos($symbol, 'declared dependency ') || 0 === strpos($symbol, 'lifecycle listener ')) {
                $dependency_risks++;
            }
        }

        $this->runtime_js_scan_current_scripts = array();

        return array(
            'available'                        => true,
            'source'                           => 'browser-runtime',
            'suggestion_count'                 => count($suggestions),
            'missing_count'                    => (int) $missing,
            'already_excluded_count'           => count($suggestions) - (int) $missing,
            'persistent_listed_failure_count'  => (int) $persistent_listed_failures,
            'dependency_risk_count'            => (int) $dependency_risks,
            'suggestions'                      => $suggestions,
        );
    }

}
