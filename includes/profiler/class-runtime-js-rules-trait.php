<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Runtime_JS_Rules_Trait
{
    /** @var array<int,array<string,mixed>> */
    private $runtime_js_scan_current_scripts = array();

    /** @var array<int,array{method:string,sources:array<int,string>}> */
    private $runtime_js_scan_resolved_jquery_plugin_contexts = array();

    private function runtime_js_scan_normalize_safeguard_lists(array $safeguards)
    {
        if (isset($safeguards['fallback']) || isset($safeguards['force']) || isset($safeguards['delay'])) {
            return array(
                'fallback' => isset($safeguards['fallback']) && is_array($safeguards['fallback']) ? $safeguards['fallback'] : array(),
                'force'    => isset($safeguards['force']) && is_array($safeguards['force']) ? $safeguards['force'] : array(),
                'delay'    => isset($safeguards['delay']) && is_array($safeguards['delay']) ? $safeguards['delay'] : array(),
            );
        }

        return array(
            'fallback' => $safeguards,
            'force'    => array(),
            'delay'    => array(),
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
            'delayed-js-interaction-bootstrap.js',
            'delayed-js-loader.js',
            'runtime-js-scan-collector.js',
            'runtime-font-css-map.js',
            'font-display-cssom-patch.js',
            'mailerlite-lazy-nonce.js',
            'lcp-request-credentials-bootstrap.js',
            'lcp-observer.js',
            'ultracache-delayed-js-loader',
            'ultracache-runtime-js-scan-collector',
            '/ultracache/js-bundles/runtime-',
            '/uploads/ultracache/js-bundles/runtime-',
            'ultracache-runtime-native',
            'ultracache-runtime-defer',
            'ultracache-runtime-delay',
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

    private function runtime_js_scan_unique_loaded_script_identity(array $script)
    {
        $src = $this->runtime_js_scan_clean_console_candidate((string) ($script['src'] ?? ''));
        if ('' !== $src) {
            return 'src:' . strtolower($src);
        }
        $handle = sanitize_key((string) ($script['handle'] ?? ''));
        if ('' !== $handle) {
            return 'handle:' . $handle;
        }
        $id = strtolower(trim((string) ($script['id'] ?? '')));
        return '' !== $id ? 'id:' . $id : '';
    }

    /**
     * Source-level runtime identity. WordPress inline companions inherit their
     * owning handle/dependencies, but they remain distinct executable segments.
     * Prefer the exact companion id before src/handle so provider attribution and
     * DOM-order analysis cannot collapse -before/-after/extra/translations into
     * the parent external script or into each other.
     */
    private function runtime_js_scan_execution_identity(array $script)
    {
        $id = strtolower(trim((string) ($script['id'] ?? '')));
        if ('' !== $id && preg_match('/-js-(?:before|after|extra|translations)$/i', $id)) {
            return 'inline-id:' . $id;
        }
        $src = $this->runtime_js_scan_clean_console_candidate((string) ($script['src'] ?? ''));
        if ('' !== $src) {
            return 'src:' . strtolower($src);
        }
        if ('' !== $id) {
            return 'id:' . $id;
        }
        $handle = sanitize_key((string) ($script['handle'] ?? ''));
        return '' !== $handle ? 'handle:' . $handle : '';
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

        // A blocking consumer executes during HTML parsing. Moving its provider
        // merely from Delay to defer is still too late, because defer executes
        // only after parsing. The provider must remain blocking as well.
        if ('blocking' === $consumer_strategy && in_array($provider_strategy, array('delay', 'defer', 'async'), true)) {
            return 'exclusion';
        }

        if ($strong_only) {
            if ('delay' === $provider_strategy && 'defer' === $consumer_strategy) {
                return 'force';
            }
            return '';
        }

        if ('delay' === $provider_strategy && 'defer' === $consumer_strategy) {
            return 'force';
        }
        if ('async' === $consumer_strategy && in_array($provider_strategy, array('delay', 'defer', 'async'), true)) {
            return 'exclusion';
        }
        if ('async' === $provider_strategy && 'delay' !== $consumer_strategy) {
            return 'exclusion';
        }
        return '';
    }

    private function runtime_js_scan_delay_consumer_suggestion($provider_strategy, $consumer_strategy, array $consumer)
    {
        $provider_strategy = strtolower(trim((string) $provider_strategy));
        $consumer_strategy = strtolower(trim((string) $consumer_strategy));
        if ('delay' !== $provider_strategy) {
            return '';
        }

        $suggestion = $this->runtime_js_scan_strong_suggestion_for_script($consumer);
        if ('' === $suggestion || $this->runtime_js_scan_is_ultracache_runtime_helper_source($suggestion) || $this->runtime_js_scan_is_generic_token($suggestion)) {
            return '';
        }
        if ('defer' === $consumer_strategy) {
            return $suggestion;
        }
        if (in_array($consumer_strategy, array('blocking', 'native'), true)) {
            $current = $this->get_runtime_js_scan_current_exclusions();
            $in_exclusion = $this->runtime_js_scan_exclusion_already_matches($suggestion, (array) ($current['fallback'] ?? array()));
            $in_force = $this->runtime_js_scan_exclusion_already_matches($suggestion, (array) ($current['force'] ?? array()));
            if ($in_exclusion || $in_force) {
                return $suggestion;
            }
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
                $delay_suggestion = $this->runtime_js_scan_delay_consumer_suggestion($provider_strategy, $consumer_strategy, $consumer);
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $suggestion,
                    'declared dependency ' . $provider_name,
                    (string) ($provider['src'] ?? ''),
                    'Page dependency graph found an execution-order conflict for provider ' . $provider_name . ' required by ' . $consumer_name . '.',
                    'WordPress registered "' . $provider_name . '" as a dependency of "' . $consumer_name . '", but the scanned page executes the provider as ' . $provider_strategy . ' while the consumer executes as ' . $consumer_strategy . '. The provider can therefore run too late for the declared dependency. When the provider is delayed and the consumer is deferred, first keep the proven consumer in the delayed execution class; if the error persists, use the existing provider-promotion safeguards and rescan.',
                    $exclusions,
                    'recommended',
                    $preferred_target,
                    false,
                    null,
                    $delay_suggestion
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

    /**
     * Additive lexical fallback for large/minified JavaScript blocks.
     *
     * The original brace extractor intentionally remains unchanged because
     * existing fixers already depend on its conservative behavior. This
     * fallback is used only by the jQuery alias/IIFE proof path when the
     * original extractor cannot close the block. It skips strings, comments,
     * and regex literals so regex quantifiers such as /x{1,3}/ cannot corrupt
     * brace depth.
     */
    private function runtime_js_scan_extract_js_brace_block_lexical($content, $open_brace_offset, $max_chars = 6000)
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
        $line_comment = false;
        $block_comment = false;
        $regex = false;
        $regex_class = false;

        for ($i = $open_brace_offset + 1; $i < $limit; $i++) {
            $char = $content[$i];
            $next = ($i + 1 < $limit) ? $content[$i + 1] : '';

            if ($line_comment) {
                if ("\n" === $char || "\r" === $char) {
                    $line_comment = false;
                }
                continue;
            }

            if ($block_comment) {
                if ('*' === $char && '/' === $next) {
                    $block_comment = false;
                    $i++;
                }
                continue;
            }

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

            if ($regex) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ('\\' === $char) {
                    $escape = true;
                    continue;
                }
                if ('[' === $char) {
                    $regex_class = true;
                    continue;
                }
                if (']' === $char && $regex_class) {
                    $regex_class = false;
                    continue;
                }
                if ('/' === $char && !$regex_class) {
                    $regex = false;
                    while ($i + 1 < $limit && preg_match('/[A-Za-z]/', $content[$i + 1])) {
                        $i++;
                    }
                }
                continue;
            }

            if (in_array($char, array("'", '"', '`'), true)) {
                $quote = $char;
                continue;
            }

            if ('/' === $char && '/' === $next) {
                $line_comment = true;
                $i++;
                continue;
            }

            if ('/' === $char && '*' === $next) {
                $block_comment = true;
                $i++;
                continue;
            }

            if ('/' === $char && $this->runtime_js_scan_js_slash_starts_regex($content, $i, $open_brace_offset)) {
                $regex = true;
                $regex_class = false;
                $escape = false;
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

    /**
     * Conservative JavaScript regex-literal discriminator for the additive
     * lexical brace fallback. Division remains the default when the preceding
     * token can end an expression.
     */
    private function runtime_js_scan_js_slash_starts_regex($content, $slash_offset, $floor_offset = 0)
    {
        $content = (string) $content;
        $slash_offset = (int) $slash_offset;
        $floor_offset = max(0, (int) $floor_offset);
        if ($slash_offset <= $floor_offset || '/' !== ($content[$slash_offset] ?? '')) {
            return true;
        }

        $i = $slash_offset - 1;
        while ($i >= $floor_offset && ctype_space($content[$i])) {
            $i--;
        }
        if ($i < $floor_offset) {
            return true;
        }

        $previous = $content[$i];
        if (false !== strpos('([{:;,=!?&|+-*%^~<>', $previous)) {
            return true;
        }
        if (')' === $previous || ']' === $previous || '}' === $previous
            || '"' === $previous || "'" === $previous || '`' === $previous
            || preg_match('/[A-Za-z0-9_$]/', $previous)) {
            $word_end = $i;
            while ($i >= $floor_offset && preg_match('/[A-Za-z_$]/', $content[$i])) {
                $i--;
            }
            $word = strtolower(substr($content, $i + 1, $word_end - $i));
            return in_array($word, array(
                'return', 'throw', 'case', 'delete', 'typeof', 'void', 'new',
                'in', 'of', 'instanceof', 'yield', 'await', 'else', 'do',
            ), true);
        }

        return false;
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

    private function runtime_js_scan_add_suggestion(&$suggestions, &$seen, $suggested_exclusion, $symbol, $source, $message, $reason, array $exclusions, $confidence = 'high', $preferred_target = '', $allow_registered_handle = false, $appendable_override = null, $delay_suggestion = '')
    {
        $suggested_exclusion = $this->runtime_js_scan_clean_console_candidate($suggested_exclusion);
        $delay_suggestion = $this->runtime_js_scan_clean_console_candidate($delay_suggestion);
        if ('' === $suggested_exclusion) {
            return;
        }
        if ($this->runtime_js_scan_is_ultracache_runtime_helper_source($suggested_exclusion) || $this->runtime_js_scan_is_ultracache_runtime_helper_source($source)) {
            return;
        }
        if (!$allow_registered_handle && $this->runtime_js_scan_is_generic_token($suggested_exclusion)) {
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
        $delay_suggestion_already_listed = '' !== $delay_suggestion
            && $this->runtime_js_scan_exclusion_already_matches($delay_suggestion, $safeguards['delay']);
        $delay_suggestion_in_exclusion = '' !== $delay_suggestion
            && $this->runtime_js_scan_exclusion_already_matches($delay_suggestion, $safeguards['fallback']);
        $delay_suggestion_in_force = '' !== $delay_suggestion
            && $this->runtime_js_scan_exclusion_already_matches($delay_suggestion, $safeguards['force']);
        $delay_suggestion_scanner_owned_exclusion = '' !== $delay_suggestion
            && $this->runtime_js_scan_policy_line_is_scanner_owned('exclusion', $delay_suggestion);
        $delay_suggestion_scanner_owned_force = '' !== $delay_suggestion
            && $this->runtime_js_scan_policy_line_is_scanner_owned('force', $delay_suggestion);
        $delay_repair_recommended = '' !== $delay_suggestion && !$delay_suggestion_already_listed
            && ($delay_suggestion_in_exclusion || $delay_suggestion_in_force);
        $delay_repair_auto_eligible = $delay_repair_recommended
            && ($delay_suggestion_scanner_owned_exclusion || $delay_suggestion_scanner_owned_force);
        $runtime_state = $this->runtime_js_scan_runtime_state_for_candidate($suggested_exclusion);
        $direct_runtime_failure = $this->runtime_js_scan_is_explicit_runtime_error($message)
            && $this->runtime_js_scan_candidate_matches_error_source($suggested_exclusion, $source);
        $still_failing_while_listed = $already_excluded && $direct_runtime_failure;
        $listed_but_ineffective = $still_failing_while_listed && !empty($runtime_state['matched'])
            && (!empty($runtime_state['delayed']) || !empty($runtime_state['deferred']) || !empty($runtime_state['async']));
        $appendable = !$ignored && !$not_fixable && !$already_excluded;
        if (null !== $appendable_override) {
            $appendable = (bool) $appendable_override && !$ignored && !$not_fixable && !$already_excluded;
        }
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
            $category = $ignored ? 'ignored' : ($not_fixable ? 'not-fixable' : ($already_excluded ? 'already-listed' : (!$appendable ? 'review-only' : ($fallback_recommended ? 'fallback-candidate' : ($is_dependency_analysis ? 'dependency-risk' : 'appendable-fix')))));
            $category_label = $ignored ? 'Ignored' : ($not_fixable ? 'Not fixable by exclusion' : ($already_excluded ? 'Already listed in Do Not Defer or Delay' : (!$appendable ? 'Review only' : ($fallback_recommended ? 'Do Not Defer or Delay candidate' : ($is_dependency_analysis ? 'Dependency risk' : 'Appendable fixes')))));
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
            'delaySuggestion'     => $delay_suggestion,
            'delaySuggestionAlreadyListed' => $delay_suggestion_already_listed,
            'delaySuggestionInExclusion' => $delay_suggestion_in_exclusion,
            'delaySuggestionInForce' => $delay_suggestion_in_force,
            'delaySuggestionScannerOwnedExclusion' => $delay_suggestion_scanner_owned_exclusion,
            'delaySuggestionScannerOwnedForce' => $delay_suggestion_scanner_owned_force,
            'delayRepairRecommended' => $delay_repair_recommended,
            'delayRepairAutoEligible' => $delay_repair_auto_eligible,
            'appendable'         => $appendable,
            'stillFailingWhileListed' => $still_failing_while_listed,
            'listedButIneffective' => $listed_but_ineffective,
            'runtimeMatchCount'  => (int) ($runtime_state['matchCount'] ?? 0),
            'runtimeStrategies'  => array_values((array) ($runtime_state['strategies'] ?? array())),
            'policyAuthority'    => 'visible-lists',
            'policyWriteMode'    => 'visible-setting-only',
            'hiddenOverride'     => false,
        );
    }

    private function runtime_js_scan_add_evidence_source_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions, array $scripts = array())
    {
        $text = (string) $source . "\n" . (string) $message . "\n" . (string) $detail;
        $candidates = array();
        $candidate_seen = array();
        $push = function ($candidate) use (&$candidates, &$candidate_seen, $scripts) {
            $candidate = $this->runtime_js_scan_clean_console_candidate((string) $candidate);
            if ('' === $candidate || $this->runtime_js_scan_is_ultracache_runtime_helper_source($candidate)) {
                return;
            }
            $base = $this->runtime_js_scan_basename_from_source($candidate);
            if ('' === $base || !preg_match('/\.js$/i', $base)) {
                return;
            }

            $owner = $this->runtime_js_scan_owner_from_script_source($candidate);
            if ($this->runtime_js_scan_is_generic_script_basename($base) && empty($owner)) {
                $matches = !empty($scripts) ? $this->runtime_js_scan_find_scripts_by_source_hint($candidate, $scripts) : array();
                if (1 !== count($matches)) {
                    return;
                }
                $matched_src = isset($matches[0]['src']) ? (string) $matches[0]['src'] : '';
                if ('' === $matched_src) {
                    return;
                }
                $candidate = $matched_src;
                $owner = $this->runtime_js_scan_owner_from_script_source($candidate);
                if (empty($owner)) {
                    return;
                }
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
                'recommended',
                'exclusion'
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

        $is_local_host = function_exists('ultracache_is_trusted_public_host')
            ? ultracache_is_trusted_public_host($host)
            : in_array($host, array(
                strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)),
                strtolower((string) wp_parse_url(site_url('/'), PHP_URL_HOST)),
            ), true);
        if ($is_local_host) {
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
        if ('' === $symbol || strlen($symbol) > 120) {
            return false;
        }

        // A symbol is actionable because the browser error named it, not
        // because UltraCache knows a hardcoded framework/global mapping.
        return (bool) preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)*$/', $symbol);
    }

    private function runtime_js_scan_is_explicit_missing_global_provider_path($path, $symbol)
    {
        $path = strtolower(trim((string) $path));
        $symbol = strtolower(str_replace(array('window.', 'globalthis.'), '', trim((string) $symbol)));
        if ('' === $path || '' === $symbol || !$this->runtime_js_scan_is_explicit_missing_global($symbol)) {
            return false;
        }

        $parts = array_values(array_filter(explode('.', $symbol), 'strlen'));
        $leaf = !empty($parts) ? (string) end($parts) : $symbol;
        $symbol_token = preg_replace('/[^a-z0-9]+/', '', $leaf);
        if (strlen((string) $symbol_token) < 4) {
            return false;
        }

        foreach ((array) preg_split('/[\s\/|]+/', $path) as $part) {
            $token = $this->runtime_js_scan_dependency_identity_token($part);
            if ('' !== $token && $token === $symbol_token) {
                return true;
            }
        }
        return false;
    }

    private function runtime_js_scan_is_actionable_missing_symbol($symbol)
    {
        return $this->runtime_js_scan_is_explicit_missing_global($symbol);
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

    private function runtime_js_scan_file_uses_missing_symbol($content, $symbol)
    {
        $content = (string) $content;
        $symbol = trim((string) $symbol);
        if ('' === $content || !$this->runtime_js_scan_is_actionable_missing_symbol($symbol)) {
            return false;
        }

        $quoted = preg_quote($symbol, '/');
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

    private function runtime_js_scan_find_scripts_defining_symbol_text($symbol, array $scripts)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol || !$this->runtime_js_scan_is_actionable_missing_symbol($symbol)) {
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
            $matches[] = $script;
            if (count($matches) >= 8) {
                break;
            }
        }
        return $matches;
    }

    private function runtime_js_scan_add_inventory_symbol_provider_suggestions(&$suggestions, &$seen, $symbol, $source, $message, $detail, array $scripts, array $exclusions)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol || !$this->runtime_js_scan_is_actionable_missing_symbol($symbol)) {
            return false;
        }

        $providers = $this->runtime_js_scan_find_scripts_defining_symbol_text($symbol, $scripts);
        if (1 !== count($providers)) {
            return false;
        }
        $consumers = $this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts);
        if (empty($consumers)) {
            return false;
        }

        $provider = (array) $providers[0];
        $consumer = (array) $consumers[0];
        $provider_strategy = $this->runtime_js_scan_script_effective_strategy($provider);
        $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($consumer);
        $preferred_target = $this->runtime_js_scan_declared_dependency_preferred_target($provider_strategy, $consumer_strategy, false);
        if ('' === $preferred_target) {
            return false;
        }

        $suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($provider);
        if ('' === $suggestion) {
            return false;
        }
        $provider_name = sanitize_text_field((string) ($provider['handle'] ?? $provider['id'] ?? $suggestion));
        $consumer_name = sanitize_text_field((string) ($consumer['handle'] ?? $consumer['id'] ?? $consumer['src'] ?? 'runtime error source'));
        $delay_suggestion = $this->runtime_js_scan_delay_consumer_suggestion($provider_strategy, $consumer_strategy, $consumer);
        $this->runtime_js_scan_add_suggestion(
            $suggestions,
            $seen,
            $suggestion,
            'runtime symbol provider ' . sanitize_text_field($symbol),
            (string) ($provider['src'] ?? ''),
            trim((string) $message . "\n" . (string) $detail),
            'The browser error names "' . sanitize_text_field($symbol) . '". Exactly one loaded script defines that symbol, and the failing consumer "' . $consumer_name . '" executes as ' . $consumer_strategy . ' while the provider executes as ' . $provider_strategy . '. When that provider is delayed and the consumer is deferred, first keep the proven consumer in the delayed execution class; if the error persists, promote only the proven provider. Unrelated scripts that merely contain similar method names are ignored.',
            $exclusions,
            'recommended',
            $preferred_target,
            true,
            null,
            $delay_suggestion
        );
        return true;
    }

    private function runtime_js_scan_dynamic_dispatch_collections_from_content($content)
    {
        $content = (string) $content;
        if ('' === $content) {
            return array();
        }

        $collections = array();
        $patterns = array(
            // jQuery.each(collection, function (key, func) { eval(func + '()'); })
            '/(?:jQuery|\$)\s*\.\s*each\s*\(\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*,\s*function\s*\(([^)]{1,160})\)\s*\{(.{0,2400}?)\}\s*\)/is',
            // collection.forEach(function (func) { eval(func + '()'); })
            '/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*\.\s*forEach\s*\(\s*function\s*\(([^)]{1,160})\)\s*\{(.{0,2400}?)\}\s*\)/is',
        );

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $collection = trim((string) ($match[1] ?? ''));
                $params_text = (string) ($match[2] ?? '');
                $body = (string) ($match[3] ?? '');
                if ('' === $collection || '' === $params_text || '' === $body) {
                    continue;
                }

                $params = array_values(array_filter(array_map('trim', explode(',', $params_text))));
                foreach ($params as $param) {
                    if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $param)) {
                        continue;
                    }
                    $quoted = preg_quote($param, '/');
                    $is_dynamic_call = (bool) preg_match('/\beval\s*\(\s*' . $quoted . '\s*\+\s*([\'\"])\(\)\1\s*\)/i', $body)
                        || (bool) preg_match('/(?:window|globalThis)\s*\[\s*' . $quoted . '\s*\]\s*\(/i', $body);
                    if (!$is_dynamic_call) {
                        continue;
                    }
                    $collections[$collection] = true;
                    break;
                }
            }
        }

        return array_keys($collections);
    }

    private function runtime_js_scan_literal_function_names_for_dispatch_collection($collection, array $scripts)
    {
        $collection = trim((string) $collection);
        if ('' === $collection || !preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $collection)) {
            return array();
        }

        $quoted_collection = preg_quote($collection, '/');
        $names = array();
        $push_name = static function ($name) use (&$names) {
            $name = trim((string) $name);
            if ('' === $name || !preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name)) {
                return;
            }
            $names[$name] = true;
        };

        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $content = $this->runtime_js_scan_script_content($script);
            if ('' === $content || false === strpos($content, $collection)) {
                continue;
            }

            // Runtime config is commonly printed as a literal object/array. Keep
            // this intentionally conservative: only inspect the literal RHS of an
            // assignment to the exact collection, never arbitrary same-owner code.
            $assignment_pattern = '/(?:^|[^A-Za-z0-9_$])(?:var\s+|let\s+|const\s+)?' . $quoted_collection . '\s*=\s*([^;]{1,20000})[;]/is';
            if (preg_match_all($assignment_pattern, $content, $assignments, PREG_SET_ORDER)) {
                foreach ($assignments as $assignment) {
                    $rhs = (string) ($assignment[1] ?? '');
                    if ('' === $rhs) {
                        continue;
                    }
                    if (preg_match_all('/([\'\"])([A-Za-z_$][A-Za-z0-9_$]*)\1/', $rhs, $literal_matches)) {
                        foreach ((array) ($literal_matches[2] ?? array()) as $literal_name) {
                            $push_name($literal_name);
                        }
                    }
                }
            }

            // Also support incremental literal population without attempting to
            // evaluate JavaScript: collection.push('fn'), collection[key]='fn'.
            $incremental_patterns = array(
                '/\b' . $quoted_collection . '\s*\.\s*push\s*\(\s*([\'\"])([A-Za-z_$][A-Za-z0-9_$]*)\1\s*\)/i',
                '/\b' . $quoted_collection . '\s*\[[^\]]{1,120}\]\s*=\s*([\'\"])([A-Za-z_$][A-Za-z0-9_$]*)\1/i',
                '/\b' . $quoted_collection . '\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*\s*=\s*([\'\"])([A-Za-z_$][A-Za-z0-9_$]*)\1/i',
            );
            foreach ($incremental_patterns as $pattern) {
                if (!preg_match_all($pattern, $content, $incremental_matches, PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($incremental_matches as $incremental_match) {
                    $push_name((string) ($incremental_match[2] ?? ''));
                }
            }
        }

        return array_slice(array_keys($names), 0, 64);
    }

    private function runtime_js_scan_add_dynamic_dispatch_missing_global_closure(&$suggestions, &$seen, $symbol, $source, $message, $detail, array $scripts, array $exclusions)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol || !$this->runtime_js_scan_is_actionable_missing_symbol($symbol)) {
            return false;
        }

        $consumers = $this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts);
        if (empty($consumers)) {
            return false;
        }

        foreach ($consumers as $consumer) {
            if (!is_array($consumer)) {
                continue;
            }
            $consumer_content = $this->runtime_js_scan_script_content($consumer);
            if ('' === $consumer_content) {
                continue;
            }
            $collections = $this->runtime_js_scan_dynamic_dispatch_collections_from_content($consumer_content);
            if (empty($collections)) {
                continue;
            }

            foreach ($collections as $collection) {
                $dispatch_names = $this->runtime_js_scan_literal_function_names_for_dispatch_collection($collection, $scripts);
                // The current ReferenceError must itself be a literal member of
                // this runtime dispatch set. This anchors expansion to observed
                // causal evidence instead of same-owner or filename proximity.
                if (!in_array($symbol, $dispatch_names, true)) {
                    continue;
                }

                $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($consumer);
                $consumer_name = sanitize_text_field((string) ($consumer['handle'] ?? $consumer['id'] ?? $consumer['src'] ?? 'dynamic dispatcher'));
                $added = false;

                foreach ($dispatch_names as $dispatch_symbol) {
                    if (!$this->runtime_js_scan_is_actionable_missing_symbol($dispatch_symbol)) {
                        continue;
                    }
                    $providers = $this->runtime_js_scan_find_scripts_defining_symbol_text($dispatch_symbol, $scripts);
                    if (1 !== count($providers)) {
                        continue;
                    }
                    $provider = (array) $providers[0];
                    if ($this->runtime_js_scan_same_inventory_script($provider, $consumer)) {
                        continue;
                    }

                    $provider_strategy = $this->runtime_js_scan_script_effective_strategy($provider);
                    $preferred_target = $this->runtime_js_scan_declared_dependency_preferred_target($provider_strategy, $consumer_strategy, false);
                    if ('' === $preferred_target) {
                        continue;
                    }
                    $suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($provider);
                    if ('' === $suggestion) {
                        continue;
                    }

                    $provider_name = sanitize_text_field((string) ($provider['handle'] ?? $provider['id'] ?? $suggestion));
                    $delay_suggestion = $this->runtime_js_scan_delay_consumer_suggestion($provider_strategy, $consumer_strategy, $consumer);
                    $this->runtime_js_scan_add_suggestion(
                        $suggestions,
                        $seen,
                        $suggestion,
                        'dynamic dispatch provider ' . sanitize_text_field($dispatch_symbol),
                        (string) ($provider['src'] ?? ''),
                        trim((string) $message . "\n" . (string) $detail),
                        'The ReferenceError names "' . sanitize_text_field($symbol) . '", and the exact failing loaded consumer "' . $consumer_name . '" dynamically dispatches literal function names from runtime collection "' . sanitize_text_field($collection) . '". The same collection also dispatches "' . sanitize_text_field($dispatch_symbol) . '", which has exactly one loaded provider "' . $provider_name . '". The provider executes as ' . $provider_strategy . ' while the dispatcher executes as ' . $consumer_strategy . ', proving an execution-order conflict. When the provider is delayed and the dispatcher is deferred, first keep the proven dispatcher in the delayed execution class; if that fails, promote only the provider. No same-owner or filename expansion is used.',
                        $exclusions,
                        'recommended',
                        $preferred_target,
                        true,
                        null,
                        $delay_suggestion
                    );
                    $added = true;
                }

                if ($added) {
                    return true;
                }
            }
        }

        return false;
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

    private function runtime_js_scan_normalize_member_expression($expression)
    {
        $expression = trim((string) $expression);
        if ('' === $expression || strlen($expression) > 240) {
            return '';
        }

        $expression = preg_replace('/\[\s*[\'\"]([A-Za-z_$][A-Za-z0-9_$-]*)[\'\"]\s*\]/', '.$1', $expression);
        $expression = preg_replace('/\s*\.\s*/', '.', (string) $expression);
        $expression = preg_replace('/\s+/', '', (string) $expression);
        if (!preg_match('/^(?:jQuery|\$|window|globalThis|this|[A-Za-z_$][A-Za-z0-9_$]*)(?:\.[A-Za-z_$][A-Za-z0-9_$-]*){0,8}$/', (string) $expression)) {
            return '';
        }

        return (string) $expression;
    }

    private function runtime_js_scan_property_receiver_expressions_from_content($content, $property, $line = 0)
    {
        $content = (string) $content;
        $property = preg_replace('/[^A-Za-z0-9_$-]/', '', trim((string) $property));
        $line = max(0, (int) $line);
        if ('' === $content || '' === $property) {
            return array();
        }

        $scopes = array();
        if ($line > 0) {
            $lines = preg_split('/\R/', $content);
            if (is_array($lines) && isset($lines[$line - 1])) {
                $start = max(0, $line - 2);
                $length = min(3, count($lines) - $start);
                $scopes[] = implode("\n", array_slice($lines, $start, $length));
            }
        }
        $scopes[] = $content;

        $property_regex = preg_quote($property, '/');
        $root = '(?:jQuery|\\$|window|globalThis|this|[A-Za-z_$][A-Za-z0-9_$]*)';
        $member = '(?:\\s*\\.\\s*[A-Za-z_$][A-Za-z0-9_$-]*|\\s*\\[\\s*[\'\"][A-Za-z_$][A-Za-z0-9_$-]*[\'\"]\\s*\\])';
        $receiver_pattern = '(' . $root . '(?:' . $member . '){0,8})';
        $patterns = array(
            '/' . $receiver_pattern . '\\s*\\.\\s*' . $property_regex . '\\b/',
            '/' . $receiver_pattern . '\\s*\\[\\s*[\'\"]' . $property_regex . '[\'\"]\\s*\\]/',
        );

        $receivers = array();
        foreach ($scopes as $scope) {
            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, (string) $scope, $matches, PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($matches as $match) {
                    $receiver = $this->runtime_js_scan_normalize_member_expression((string) ($match[1] ?? ''));
                    if ('' === $receiver) {
                        continue;
                    }
                    $key = strtolower($receiver);
                    $receivers[$key] = $receiver;
                    if (count($receivers) >= 8) {
                        break 3;
                    }
                }
            }
            if (!empty($receivers)) {
                break;
            }
        }

        return array_values($receivers);
    }

    private function runtime_js_scan_jquery_method_from_member_expression($expression)
    {
        $expression = $this->runtime_js_scan_normalize_member_expression($expression);
        if ('' === $expression || !preg_match('/^(?:jQuery|\\$)\\.fn\\.([A-Za-z_$][A-Za-z0-9_$-]*)$/', $expression, $match)) {
            return '';
        }
        return sanitize_text_field((string) ($match[1] ?? ''));
    }

    private function runtime_js_scan_member_expression_pattern($expression)
    {
        $expression = $this->runtime_js_scan_normalize_member_expression($expression);
        if ('' === $expression) {
            return '';
        }
        $parts = array_values(array_filter(explode('.', $expression), 'strlen'));
        if (empty($parts)) {
            return '';
        }

        $regex = '';
        foreach ($parts as $index => $part) {
            if (0 === $index && in_array($part, array('$', 'jQuery'), true)) {
                $token = '(?:jQuery|\\$)';
            } else {
                $token = preg_quote($part, '/');
            }
            $regex .= (0 === $index ? '' : '\\s*\\.\\s*') . $token;
        }
        return $regex;
    }

    private function runtime_js_scan_file_defines_member_expression($content, $expression)
    {
        $content = (string) $content;
        $expression = $this->runtime_js_scan_normalize_member_expression($expression);
        if ('' === $content || '' === $expression) {
            return false;
        }

        $jquery_method = $this->runtime_js_scan_jquery_method_from_member_expression($expression);
        if ('' !== $jquery_method) {
            return $this->runtime_js_scan_jquery_file_defines_method($content, $jquery_method);
        }

        $expression_regex = $this->runtime_js_scan_member_expression_pattern($expression);
        if ('' === $expression_regex) {
            return false;
        }

        if (preg_match('/(?:^|[^A-Za-z0-9_$])' . $expression_regex . '\\s*=\\s*(?!=)/m', $content)) {
            return true;
        }
        if (preg_match('/Object\\s*\\.\\s*assign\\s*\\(\\s*' . $expression_regex . '\\s*,/i', $content)) {
            return true;
        }

        return false;
    }

    private function runtime_js_scan_find_member_expression_provider_scripts($expression, array $scripts, array $consumer = array())
    {
        $expression = $this->runtime_js_scan_normalize_member_expression($expression);
        if ('' === $expression || empty($scripts)) {
            return array();
        }

        $consumer_src = strtolower($this->runtime_js_scan_clean_console_candidate((string) ($consumer['src'] ?? '')));
        $jquery_method = $this->runtime_js_scan_jquery_method_from_member_expression($expression);
        $providers = array();
        $seen = array();

        if ('' !== $jquery_method) {
            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }
                $src = strtolower($this->runtime_js_scan_clean_console_candidate((string) ($script['src'] ?? '')));
                if ('' !== $consumer_src && '' !== $src && $src === $consumer_src) {
                    continue;
                }
                $content = $this->runtime_js_scan_script_content($script);
                // For a receiver proven from source code, do not use filename
                // or handle identity heuristics. Require the actual jQuery.fn
                // assignment so an adjacent library with a similar name cannot
                // be mistaken for the jQuery plugin provider.
                if (!$this->runtime_js_scan_jquery_file_defines_method($content, $jquery_method, '')) {
                    continue;
                }
                $key = $this->runtime_js_scan_execution_identity($script);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $providers[] = $script;
                if (count($providers) >= 8) {
                    break;
                }
            }
            return $providers;
        }

        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $src = strtolower($this->runtime_js_scan_clean_console_candidate((string) ($script['src'] ?? '')));
            if ('' !== $consumer_src && '' !== $src && $src === $consumer_src) {
                continue;
            }
            $content = $this->runtime_js_scan_script_content($script);
            if (!$this->runtime_js_scan_file_defines_member_expression($content, $expression)) {
                continue;
            }
            $key = $this->runtime_js_scan_execution_identity($script);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $providers[] = $script;
            if (count($providers) >= 8) {
                break;
            }
        }

        return $providers;
    }

    private function runtime_js_scan_select_member_expression_provider_for_consumer(array $providers, array $consumer)
    {
        if (empty($providers)) {
            return array();
        }

        /*
         * Scanner-first attribution prefers the delayed loader's observed
         * execution sequence when both sides expose it. DOM order remains the
         * fallback for browser-owned NATIVE/DEFER scripts. This changes only
         * diagnostic attribution; it never creates an execution exception.
         */
        $consumer_execution = isset($consumer['executionSequence']) ? (int) $consumer['executionSequence'] : 0;
        if ($consumer_execution > 0) {
            $before_execution = array();
            foreach ($providers as $provider) {
                if (!is_array($provider)) {
                    continue;
                }
                $provider_execution = isset($provider['executionSequence']) ? (int) $provider['executionSequence'] : 0;
                if ($provider_execution > 0 && $provider_execution < $consumer_execution) {
                    $before_execution[] = $provider;
                }
            }
            if (!empty($before_execution)) {
                usort($before_execution, static function ($left, $right) {
                    return ((int) ($right['executionSequence'] ?? 0)) <=> ((int) ($left['executionSequence'] ?? 0));
                });
                return (array) $before_execution[0];
            }
        }

        $consumer_order = isset($consumer['order']) ? (int) $consumer['order'] : -1;
        if ($consumer_order >= 0) {
            $before = array();
            foreach ($providers as $provider) {
                if (!is_array($provider) || !isset($provider['order'])) {
                    continue;
                }
                $provider_order = (int) $provider['order'];
                if ($provider_order < $consumer_order) {
                    $before[] = $provider;
                }
            }
            if (!empty($before)) {
                usort($before, static function ($left, $right) {
                    return ((int) ($right['order'] ?? -1)) <=> ((int) ($left['order'] ?? -1));
                });
                return (array) $before[0];
            }
        }
        return 1 === count($providers) ? (array) $providers[0] : array();
    }

    private function runtime_js_scan_exact_loaded_scripts_for_source($source, array $scripts, array $exclude_scripts = array())
    {
        $source = $this->runtime_js_scan_clean_console_candidate((string) $source);
        if ('' === $source || empty($scripts)) {
            return array();
        }

        $source_path = (string) wp_parse_url($source, PHP_URL_PATH);
        if ('' === $source_path) {
            $source_path = $source;
        }
        $source_path = strtolower('/' . ltrim((string) $source_path, '/'));

        $excluded = array();
        foreach ($exclude_scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $key = $this->runtime_js_scan_unique_loaded_script_identity($script);
            if ('' !== trim($key, '|')) {
                $excluded[$key] = true;
            }
        }

        $matches = array();
        $seen = array();
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $key = $this->runtime_js_scan_unique_loaded_script_identity($script);
            if (isset($excluded[$key]) || isset($seen[$key])) {
                continue;
            }
            $script_src = $this->runtime_js_scan_clean_console_candidate((string) ($script['src'] ?? ''));
            if ('' === $script_src) {
                continue;
            }
            $script_path = (string) wp_parse_url($script_src, PHP_URL_PATH);
            if ('' === $script_path) {
                $script_path = $script_src;
            }
            $script_path = strtolower('/' . ltrim((string) $script_path, '/'));
            if ($script_path !== $source_path) {
                continue;
            }
            $seen[$key] = true;
            $matches[] = $script;
            if (count($matches) > 1) {
                break;
            }
        }

        return $matches;
    }

    private function runtime_js_scan_find_member_expression_provider_scripts_with_filesystem_fallback($expression, array $scripts, array $consumer, $source, $message, $detail)
    {
        $providers = $this->runtime_js_scan_find_member_expression_provider_scripts($expression, $scripts, $consumer);
        if (!empty($providers)) {
            return $providers;
        }

        $jquery_method = $this->runtime_js_scan_jquery_method_from_member_expression($expression);
        if ('' === $jquery_method) {
            return array();
        }

        // The browser inventory can preserve an external script identity even when
        // its local content could not be read from that particular inventory entry.
        // Reuse the targeted owner-filesystem scanner to prove the exact jQuery.fn
        // provider, then accept it only when that exact public path is also loaded
        // on this page. Filesystem discovery alone is never enough for an auto-fix.
        $context = $this->runtime_js_scan_find_jquery_plugin_filesystem_context(
            $jquery_method,
            $source,
            $message,
            $detail,
            false
        );
        $resolved = array();
        $seen = array();
        foreach ((array) ($context['providers'] ?? array()) as $definition) {
            if (!is_array($definition) || empty($definition['src'])) {
                continue;
            }
            foreach ($this->runtime_js_scan_exact_loaded_scripts_for_source((string) $definition['src'], $scripts, array($consumer)) as $script) {
                $key = $this->runtime_js_scan_unique_loaded_script_identity($script);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $resolved[] = $script;
                if (count($resolved) > 1) {
                    return $resolved;
                }
            }
        }

        return $resolved;
    }

    private function runtime_js_scan_find_unique_window_symbol_provider_with_owner_fallback($symbol, array $scripts, array $exclude_scripts, $owner_source)
    {
        $provider = $this->runtime_js_scan_find_unique_window_symbol_provider($symbol, $scripts, $exclude_scripts);
        if (!empty($provider)) {
            return $provider;
        }

        $owner = $this->runtime_js_scan_owner_group_from_source((string) $owner_source);
        if (empty($owner['kind']) || empty($owner['slug'])) {
            return array();
        }
        $root = $this->runtime_js_scan_owner_root_for_discovery($owner);
        if (empty($root['dir']) || empty($root['uri'])) {
            return array();
        }

        $kind = (string) ($root['kind'] ?? $owner['kind']);
        $root_dir = (string) $root['dir'];
        $root_uri = (string) $root['uri'];
        $files = 'plugin' === $kind
            ? $this->runtime_js_scan_plugin_stage_files($root_dir, 140, 7)
            : $this->runtime_js_scan_theme_stage_files($root_dir, 120, 7);

        $resolved = array();
        $seen = array();
        foreach ($files as $file) {
            $content = function_exists('ultracache_guarded_asset_file_get_contents')
                ? ultracache_guarded_asset_file_get_contents($file, 'js', 'runtime_js_window_provider_fallback', true)
                : false;
            if (!is_string($content) || !$this->runtime_js_scan_file_defines_window_symbol($content, $symbol)) {
                continue;
            }
            $relative = $this->runtime_js_scan_theme_stage_relative_path($file, $root_dir);
            if ('' === $relative) {
                continue;
            }
            $definition_url = esc_url_raw(trailingslashit($root_uri) . ltrim($relative, '/'));
            foreach ($this->runtime_js_scan_exact_loaded_scripts_for_source($definition_url, $scripts, $exclude_scripts) as $script) {
                $key = $this->runtime_js_scan_unique_loaded_script_identity($script);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $resolved[] = $script;
                if (count($resolved) > 1) {
                    return array();
                }
            }
        }

        return 1 === count($resolved) ? (array) $resolved[0] : array();
    }

    private function runtime_js_scan_explicit_window_prerequisites_from_content($content)
    {
        $content = (string) $content;
        if ('' === $content) {
            return array();
        }

        $symbols = array();
        $push = function ($symbol) use (&$symbols) {
            $symbol = trim((string) $symbol);
            if ('' === $symbol || !$this->runtime_js_scan_is_actionable_missing_symbol($symbol)) {
                return;
            }
            $symbols[strtolower($symbol)] = $symbol;
        };

        $patterns = array(
            '/if\\s*\\(\\s*!\\s*(?:window|globalThis)\\s*\\.\\s*([A-Za-z_$][A-Za-z0-9_$]*)\\s*\\)\\s*(?:\\{\\s*)?throw\\s+new\\s+Error\\b/i',
            '/if\\s*\\(\\s*(?:typeof\\s+)?(?:window|globalThis)\\s*\\.\\s*([A-Za-z_$][A-Za-z0-9_$]*)\\s*(?:===|==)\\s*[\'\"]undefined[\'\"]\\s*\\)\\s*(?:\\{\\s*)?throw\\s+new\\s+Error\\b/i',
            '/if\\s*\\(\\s*[\'\"]undefined[\'\"]\\s*(?:===|==)\\s*typeof\\s+(?:window|globalThis)\\s*\\.\\s*([A-Za-z_$][A-Za-z0-9_$]*)\\s*\\)\\s*(?:\\{\\s*)?throw\\s+new\\s+Error\\b/i',
        );
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $push((string) ($match[1] ?? ''));
            }
        }

        return array_values($symbols);
    }

    private function runtime_js_scan_file_defines_window_symbol($content, $symbol)
    {
        $content = (string) $content;
        $symbol = trim((string) $symbol);
        if ('' === $content || !$this->runtime_js_scan_is_actionable_missing_symbol($symbol)) {
            return false;
        }
        if ($this->runtime_js_scan_file_defines_symbol($content, $symbol)) {
            return true;
        }

        $quoted = preg_quote($symbol, '/');
        if (preg_match('/(?:window|globalThis|this)\\s*\\.\\s*' . $quoted . '\\s*=\\s*(?!=)/i', $content)) {
            return true;
        }

        // UMD wrappers commonly receive the browser global (`this`) through a
        // short alias and assign exported symbols through that alias. Treat this
        // as global-provider evidence only when the same wrapper is invoked with
        // a browser-global root and the aliased assignment is present.
        if (preg_match_all('/(?:!|\\()\\s*function\\s*\\(\\s*([A-Za-z_$][A-Za-z0-9_$]*)\\s*,/i', $content, $wrappers, PREG_SET_ORDER)) {
            foreach ($wrappers as $wrapper) {
                $alias = (string) ($wrapper[1] ?? '');
                if ('' === $alias) {
                    continue;
                }
                $alias_regex = preg_quote($alias, '/');
                $invoked_with_global = (bool) preg_match('/\\}\\s*\\(\\s*(?:this|window|globalThis)\\s*,/i', $content);
                $assigns_symbol = (bool) preg_match('/\\b' . $alias_regex . '\\s*\\.\\s*' . $quoted . '\\s*=\\s*(?!=)/', $content);
                if ($invoked_with_global && $assigns_symbol) {
                    return true;
                }
            }
        }

        return false;
    }

    private function runtime_js_scan_find_unique_window_symbol_provider($symbol, array $scripts, array $exclude_scripts = array())
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol || empty($scripts) || !$this->runtime_js_scan_is_actionable_missing_symbol($symbol)) {
            return array();
        }

        $excluded = array();
        foreach ($exclude_scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $key = $this->runtime_js_scan_unique_loaded_script_identity($script);
            if ('' !== trim($key, '|')) {
                $excluded[$key] = true;
            }
        }

        $providers = array();
        $seen = array();
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            $key = $this->runtime_js_scan_unique_loaded_script_identity($script);
            if (isset($excluded[$key]) || isset($seen[$key])) {
                continue;
            }
            $content = $this->runtime_js_scan_script_content($script);
            if (!$this->runtime_js_scan_file_defines_window_symbol($content, $symbol)) {
                continue;
            }
            $seen[$key] = true;
            $providers[] = $script;
            if (count($providers) > 1) {
                return array();
            }
        }

        return 1 === count($providers) ? (array) $providers[0] : array();
    }

    private function runtime_js_scan_strategy_after_preferred_target($current_strategy, $preferred_target)
    {
        $current_strategy = strtolower(trim((string) $current_strategy));
        $preferred_target = strtolower(trim((string) $preferred_target));
        if ('exclusion' === $preferred_target) {
            return 'blocking';
        }
        if ('force' === $preferred_target) {
            return 'defer';
        }
        return $current_strategy;
    }

    private function runtime_js_scan_observed_missing_receiver_preferred_target($provider_strategy)
    {
        $provider_strategy = strtolower(trim((string) $provider_strategy));

        // For this error class the browser has already proved that the receiver
        // was absent when the consumer executed. Once source inspection resolves
        // exactly one loaded provider for that receiver, do not require strategy
        // labels to prove the same ordering failure a second time. Strategy is
        // used only to choose the least-invasive deterministic repair.
        if ('delay' === $provider_strategy) {
            return 'force';
        }
        if (in_array($provider_strategy, array('defer', 'async'), true)) {
            return 'exclusion';
        }

        // A provider already observed as blocking cannot be made earlier by the
        // JavaScript safeguard lists. Keep that case review-only instead of
        // pretending an exclusion would change execution order.
        return '';
    }

    private function runtime_js_scan_add_undefined_property_provider_chain_suggestions(&$suggestions, &$seen, $source, $message, $detail, $line, array $scripts, array $exclusions)
    {
        $reads = $this->runtime_js_scan_extract_undefined_property_reads_from_error($message, $detail);
        if (empty($reads)) {
            return false;
        }
        $execution_context = $this->runtime_js_scan_error_execution_consumer_context($source, $message, $detail, $scripts);
        $inline_execution_consumer = !empty($execution_context['isInlineCompanion']) && !empty($execution_context['execution'])
            ? (array) $execution_context['execution']
            : array();
        $inline_policy_owner = !empty($execution_context['isInlineCompanion']) && !empty($execution_context['policyOwner'])
            ? (array) $execution_context['policyOwner']
            : array();
        $consumers = !empty($inline_execution_consumer)
            ? array($inline_execution_consumer)
            : $this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts);
        if (empty($consumers)) {
            return false;
        }

        $read = (array) $reads[0];
        $property = sanitize_text_field((string) ($read['property'] ?? 'property'));
        $state = sanitize_text_field((string) ($read['state'] ?? 'undefined'));

        // One physical browser script can be represented by more than one runtime
        // inventory record. Do not reject the provider repair merely because the
        // error-scoped consumer resolver returned duplicate/mixed representations.
        // Instead, resolve each usable consumer independently and continue only
        // when every usable proof converges on one receiver/provider relationship.
        $resolved = array();
        foreach ($consumers as $consumer_candidate) {
            if (!is_array($consumer_candidate)) {
                continue;
            }
            $candidate_content = $this->runtime_js_scan_script_content($consumer_candidate);
            if ('' === $candidate_content) {
                continue;
            }
            $receivers = $this->runtime_js_scan_property_receiver_expressions_from_content($candidate_content, $property, $line);
            foreach ($receivers as $receiver) {
                $providers = $this->runtime_js_scan_find_member_expression_provider_scripts_with_filesystem_fallback($receiver, $scripts, $consumer_candidate, $source, $message, $detail);
                $provider = $this->runtime_js_scan_select_member_expression_provider_for_consumer($providers, $consumer_candidate);
                if (empty($provider)) {
                    continue;
                }
                $provider_identity = $this->runtime_js_scan_dependency_suggestion_for_script($provider);
                if ('' === $provider_identity) {
                    $provider_identity = $this->runtime_js_scan_execution_identity($provider);
                }
                if ('' === $provider_identity) {
                    continue;
                }
                $key = strtolower($receiver . '|' . $provider_identity);
                if (!isset($resolved[$key])) {
                    $resolved[$key] = array(
                        'receiver' => $receiver,
                        'provider' => $provider,
                        'consumer' => $consumer_candidate,
                        'policyConsumer' => !empty($inline_policy_owner) ? $inline_policy_owner : $consumer_candidate,
                    );
                }
            }
        }
        if (1 !== count($resolved)) {
            return false;
        }

        $pair = (array) reset($resolved);
        $consumer = isset($pair['consumer']) && is_array($pair['consumer']) ? $pair['consumer'] : array();
        $policy_consumer = isset($pair['policyConsumer']) && is_array($pair['policyConsumer']) ? $pair['policyConsumer'] : $consumer;
        $receiver = sanitize_text_field((string) ($pair['receiver'] ?? 'runtime object'));
        $provider = isset($pair['provider']) && is_array($pair['provider']) ? $pair['provider'] : array();
        if (empty($consumer) || empty($policy_consumer) || empty($provider)) {
            return false;
        }

        $provider_strategy = $this->runtime_js_scan_script_effective_strategy($provider);
        $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($policy_consumer);
        $provider_name = sanitize_text_field((string) ($provider['id'] ?? $provider['handle'] ?? $provider['src'] ?? 'runtime provider'));
        $consumer_name = sanitize_text_field((string) ($consumer['id'] ?? $consumer['handle'] ?? $consumer['src'] ?? 'runtime consumer'));
        $policy_consumer_name = sanitize_text_field((string) ($policy_consumer['handle'] ?? $policy_consumer['id'] ?? $policy_consumer['src'] ?? $consumer_name));
        $line_text = (int) $line > 0 ? ' at browser line ' . (int) $line : '';

        $preferred_target = $this->runtime_js_scan_declared_dependency_preferred_target($provider_strategy, $consumer_strategy, false);
        $observed_order_failure = false;
        if ('' === $preferred_target) {
            $preferred_target = $this->runtime_js_scan_observed_missing_receiver_preferred_target($provider_strategy);
            $observed_order_failure = '' !== $preferred_target;
        }

        $added = false;
        $planned_provider_strategy = $provider_strategy;
        if ('' !== $preferred_target) {
            $provider_suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($provider);
            if ('' !== $provider_suggestion) {
                $delay_suggestion = $this->runtime_js_scan_delay_consumer_suggestion($provider_strategy, $consumer_strategy, $policy_consumer);
                $reason = 'The browser reports that the exact loaded execution segment "' . $consumer_name . '" reads property "' . $property . '" from ' . $state . $line_text . '. Runtime Scan inspected that exact segment and resolved the receiver before the failing property as "' . $receiver . '". Exact source-definition evidence resolved "' . $provider_name . '" as the applicable receiver writer; observed delayed execution sequence is preferred when available, with page DOM order as the browser-owned fallback for multiple writers. ' . ($observed_order_failure ? 'The browser failure itself proves that this receiver was not available when the consumer ran, even though both scanned strategy labels may otherwise look order-compatible. ' : 'The scanned execution strategies independently prove that the provider can run too late. ') . 'The provider executes as ' . $provider_strategy . ', while visible policy for its WordPress owner "' . $policy_consumer_name . '" executes as ' . $consumer_strategy . '. If the provider is delayed and that owner was moved earlier by a visible UltraCache safeguard, first move the exact owner back into the same delayed execution island; otherwise use the existing least-invasive provider promotion and rescan.';
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $provider_suggestion,
                    'undefined property provider ' . $receiver,
                    (string) ($provider['src'] ?? ''),
                    trim((string) $message . "\n" . (string) $detail),
                    $reason,
                    $exclusions,
                    'recommended',
                    $preferred_target,
                    true,
                    null,
                    $delay_suggestion
                );
                $planned_provider_strategy = $this->runtime_js_scan_strategy_after_preferred_target($provider_strategy, $preferred_target);
                $added = true;
            }
        }

        // Inspect one explicit upstream prerequisite even when the direct receiver
        // provider is already blocking. A blocking direct provider may have failed
        // before defining the receiver because its own prerequisite ran too late.
        // In that case moving the direct provider cannot help, but moving the unique
        // upstream provider before it can deterministically repair the observed chain.
        $provider_content = $this->runtime_js_scan_script_content($provider);
        foreach ($this->runtime_js_scan_explicit_window_prerequisites_from_content($provider_content) as $symbol) {
            if (in_array(strtolower($symbol), array('jquery', '$'), true)) {
                continue;
            }
            $upstream = $this->runtime_js_scan_find_unique_window_symbol_provider_with_owner_fallback($symbol, $scripts, array($consumer, $policy_consumer, $provider), (string) ($provider['src'] ?? ''));
            if (empty($upstream)) {
                continue;
            }
            $upstream_strategy = $this->runtime_js_scan_script_effective_strategy($upstream);
            $upstream_target = $this->runtime_js_scan_declared_dependency_preferred_target($upstream_strategy, $planned_provider_strategy, false);
            if ('' === $upstream_target) {
                continue;
            }
            $upstream_suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($upstream);
            if ('' === $upstream_suggestion) {
                continue;
            }
            $upstream_name = sanitize_text_field((string) ($upstream['handle'] ?? $upstream['id'] ?? $upstream_suggestion));
            $direct_provider_state = $added
                ? 'After applying the direct-provider repair, "' . $provider_name . '" would execute as ' . $planned_provider_strategy . '.'
                : 'The direct receiver provider "' . $provider_name . '" is already blocking, so moving it earlier cannot repair the failure.';
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $upstream_suggestion,
                'undefined property upstream provider ' . $symbol,
                (string) ($upstream['src'] ?? ''),
                trim((string) $message . "\n" . (string) $detail),
                'Runtime Scan proved that "' . $provider_name . '" supplies the missing runtime receiver "' . $receiver . '" and that this provider explicitly aborts when window.' . sanitize_text_field($symbol) . ' is unavailable. Exactly one loaded script, "' . $upstream_name . '", defines that prerequisite. ' . $direct_provider_state . ' The upstream script executes as ' . $upstream_strategy . ' while its consumer executes as ' . $planned_provider_strategy . ', so protect this upstream provider as the deterministic dependency edge.',
                $exclusions,
                'recommended',
                $upstream_target,
                true
            );
            $added = true;
        }

        return $added;
    }

    private function runtime_js_scan_add_undefined_property_consumer_suggestion(&$suggestions, &$seen, $source, $message, $detail, $line, array $scripts, array $exclusions)
    {
        $reads = $this->runtime_js_scan_extract_undefined_property_reads_from_error($message, $detail);
        if (empty($reads)) {
            return false;
        }

        $read = (array) $reads[0];
        $property = sanitize_text_field((string) ($read['property'] ?? 'property'));
        $state = sanitize_text_field((string) ($read['state'] ?? 'undefined'));
        $execution_context = $this->runtime_js_scan_error_execution_consumer_context($source, $message, $detail, $scripts);
        $consumers = !empty($execution_context['execution'])
            ? array((array) $execution_context['execution'])
            : $this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts);

        if (empty($consumers)) {
            $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($source, 5);
            if ('' === $fragment) {
                return false;
            }
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $fragment,
                'undefined property runtime source: ' . $property,
                $source,
                trim((string) $message . "\n" . (string) $detail),
                'The browser identified this exact plugin/theme script as the source of a TypeError reading "' . $property . '" from ' . $state . ', but Runtime Scan could not resolve the loaded consumer or a deterministic provider relationship. Keep the exact source visible for review instead of guessing from the generic filename or treating the property name as a missing global.',
                $exclusions,
                'review',
                '',
                false,
                false
            );
            return true;
        }

        $consumer = (array) $consumers[0];
        $policy_consumer = !empty($execution_context['policyOwner']) ? (array) $execution_context['policyOwner'] : $consumer;
        $suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($policy_consumer);
        if ('' === $suggestion) {
            return false;
        }

        $content = $this->runtime_js_scan_script_content($consumer);
        $property_in_consumer = '' !== $content && (bool) preg_match('/(?:\.\s*' . preg_quote($property, '/') . '\b|\[\s*[\'"]' . preg_quote($property, '/') . '[\'"]\s*\])/i', $content);
        $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($policy_consumer);
        $consumer_name = sanitize_text_field((string) ($consumer['id'] ?? $consumer['handle'] ?? $consumer['src'] ?? $suggestion));
        $policy_consumer_name = sanitize_text_field((string) ($policy_consumer['handle'] ?? $policy_consumer['id'] ?? $policy_consumer['src'] ?? $suggestion));
        $line = max(0, (int) $line);
        $line_text = $line > 0 ? ' at browser line ' . $line : '';

        // A "Cannot read properties of undefined/null" error does not name the
        // receiver, so the property token is not proof of a missing provider.
        // Declared dependency repair has already run before this fallback. Only
        // move the exact consumer when its fetched source contains the reported
        // property and UltraCache currently changes that consumer's execution
        // strategy. Otherwise retain precise review evidence without auto-fixing.
        $preferred_target = '';
        $confidence = 'review';
        $appendable_override = false;
        if ($property_in_consumer && 'delay' === $consumer_strategy) {
            $preferred_target = 'force';
            $confidence = 'recommended';
            $appendable_override = null;
            $reason = 'The browser reports that "' . $consumer_name . '" reads property "' . $property . '" from ' . $state . $line_text . '. Runtime Scan resolved the exact loaded source and confirmed that its fetched JavaScript contains that property access. The consumer is currently delayed by UltraCache, so move only this exact consumer to Defer Instead and rescan. The property name itself is not treated as a missing provider.';
        } elseif ($property_in_consumer && in_array($consumer_strategy, array('defer', 'async'), true)) {
            $preferred_target = 'exclusion';
            $confidence = 'recommended';
            $appendable_override = null;
            $reason = 'The browser reports that "' . $consumer_name . '" reads property "' . $property . '" from ' . $state . $line_text . '. Runtime Scan resolved the exact loaded source and confirmed that its fetched JavaScript contains that property access. The consumer currently runs as ' . $consumer_strategy . ', so keep only this exact consumer blocking with Do Not Defer or Delay and rescan. The property name itself is not treated as a missing provider.';
        } else {
            $resolution_note = '';
            if ($property_in_consumer) {
                $receiver_candidates = $this->runtime_js_scan_property_receiver_expressions_from_content($content, $property, $line);
                if (1 === count($receiver_candidates)) {
                    $review_receiver = (string) $receiver_candidates[0];
                    $review_providers = $this->runtime_js_scan_find_member_expression_provider_scripts_with_filesystem_fallback(
                        $review_receiver,
                        $scripts,
                        $consumer,
                        $source,
                        $message,
                        $detail
                    );
                    if (empty($review_providers)) {
                        $resolution_note = ' Runtime Scan resolved the receiver as "' . sanitize_text_field($review_receiver) . '", but no exact scanned execution segment could be proven as its provider.';
                    } elseif (count($review_providers) > 1) {
                        $resolution_note = ' Runtime Scan resolved the receiver as "' . sanitize_text_field($review_receiver) . '", but more than one scanned provider execution segment remained possible.';
                    } else {
                        $review_provider = (array) $review_providers[0];
                        $review_provider_name = sanitize_text_field((string) ($review_provider['handle'] ?? $review_provider['id'] ?? $review_provider['src'] ?? 'runtime provider'));
                        $review_provider_strategy = $this->runtime_js_scan_script_effective_strategy($review_provider);
                        $resolution_note = ' Runtime Scan proved "' . $review_provider_name . '" as the receiver provider, but its current ' . $review_provider_strategy . ' execution state and any explicit one-hop prerequisite did not yield an earlier appendable safeguard.';
                    }
                } elseif (count($receiver_candidates) > 1) {
                    $resolution_note = ' More than one receiver expression matched the reported property near the failing source, so provider attribution remained ambiguous.';
                } else {
                    $resolution_note = ' Runtime Scan could not resolve one receiver expression for the reported property from the fetched source.';
                }
            }
            $reason = 'The browser reports that the exact loaded execution segment "' . $consumer_name . '" reads property "' . $property . '" from ' . $state . $line_text . '. ' . ($property_in_consumer ? 'Runtime Scan confirmed the property access inside that exact segment.' : 'The fetched source did not provide enough matching property evidence.') . $resolution_note . ' Changing visible policy for its WordPress owner "' . $policy_consumer_name . '" cannot be justified automatically in its current execution state. Keep the exact owner-relative source as review evidence.';
        }

        $this->runtime_js_scan_add_suggestion(
            $suggestions,
            $seen,
            $suggestion,
            'undefined property runtime consumer: ' . $property,
            (string) ($consumer['src'] ?? $source),
            trim((string) $message . "\n" . (string) $detail),
            $reason,
            $exclusions,
            $confidence,
            $preferred_target,
            true,
            $appendable_override
        );
        return true;
    }

    private function runtime_js_scan_add_wrong_type_consumer_strategy_suggestion(&$suggestions, &$seen, $source, $message, $detail, array $scripts, array $exclusions)
    {
        $receivers = $this->runtime_js_scan_extract_wrong_type_member_receivers_from_error($message, $detail);
        if (empty($receivers)) {
            return false;
        }

        $consumers = $this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts);
        if (empty($consumers)) {
            return false;
        }

        $consumer = (array) $consumers[0];
        $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($consumer);
        $suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($consumer);
        if ('' === $suggestion) {
            return false;
        }

        $receiver = sanitize_text_field((string) ($receivers[0]['receiver'] ?? 'runtime value'));
        $member = sanitize_text_field((string) ($receivers[0]['member'] ?? ''));
        $expression = '' !== $member ? ($receiver . '.' . $member) : $receiver;
        $consumer_name = sanitize_text_field((string) ($consumer['handle'] ?? $consumer['id'] ?? $consumer['src'] ?? $suggestion));

        $preferred_target = '';
        $confidence = 'recommended';
        $appendable_override = null;
        $reason = '';
        if ('delay' === $consumer_strategy) {
            $preferred_target = 'force';
            $reason = 'The browser reports "' . $expression . ' is not a function", which proves the receiver already exists but has the wrong runtime state/type when the failing consumer "' . $consumer_name . '" runs. The consumer is currently delayed, so move only this exact consumer to Defer Instead and rescan. Missing-provider discovery is intentionally skipped for this TypeError.';
        } elseif (in_array($consumer_strategy, array('defer', 'async'), true)) {
            $preferred_target = 'exclusion';
            $reason = 'The browser reports "' . $expression . ' is not a function", which proves the receiver already exists but has the wrong runtime state/type when the failing consumer "' . $consumer_name . '" runs. The consumer is already non-delayed (' . $consumer_strategy . '), so keep only this exact consumer blocking with Do Not Defer or Delay and rescan. Missing-provider discovery is intentionally skipped for this TypeError.';
        } else {
            $confidence = 'review';
            $appendable_override = false;
            $reason = 'The browser reports "' . $expression . ' is not a function", which proves the receiver already exists but has the wrong runtime state/type. The failing consumer "' . $consumer_name . '" is already blocking, so changing its UltraCache execution strategy cannot move it earlier. Keep this as review evidence instead of inventing a missing-provider fix.';
        }

        $this->runtime_js_scan_add_suggestion(
            $suggestions,
            $seen,
            $suggestion,
            'wrong-type runtime consumer: ' . $expression,
            (string) ($consumer['src'] ?? $source),
            trim((string) $message . "\n" . (string) $detail),
            $reason,
            $exclusions,
            $confidence,
            $preferred_target,
            true,
            $appendable_override
        );
        return true;
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


    private function runtime_js_scan_extract_computed_window_call_variables_from_error($message, $detail = '')
    {
        $text = (string) $message . "\n" . (string) $detail;
        $variables = array();
        if (preg_match_all('/window\s*\[\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\]\s+is\s+not\s+a\s+function/i', $text, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $variable) {
                $variable = sanitize_text_field((string) $variable);
                if ('' === $variable) {
                    continue;
                }
                $variables[strtolower($variable)] = $variable;
            }
        }
        return array_values($variables);
    }

    private function runtime_js_scan_script_matches_owner_context(array $script, array $owner)
    {
        $kind = isset($owner['kind']) ? sanitize_key((string) $owner['kind']) : '';
        $slug = isset($owner['slug']) ? sanitize_key((string) $owner['slug']) : '';
        if ('' === $kind || '' === $slug) {
            return false;
        }

        $src = isset($script['src']) ? (string) $script['src'] : '';
        if ('' !== $src) {
            $script_owner = $this->runtime_js_scan_owner_from_script_source($src);
            if (!empty($script_owner)
                && $kind === sanitize_key((string) ($script_owner['kind'] ?? ''))
                && $slug === sanitize_key((string) ($script_owner['slug'] ?? ''))) {
                return true;
            }
        }

        // WordPress inline companions do not have a source URL. Their handle/id
        // normally carries the owning plugin/theme slug; use that only as a
        // companion-context proof, never as standalone provider evidence.
        $owner_token = preg_replace('/[^a-z0-9]+/', '', strtolower($slug));
        if (strlen((string) $owner_token) < 4) {
            return false;
        }
        $identity = strtolower((string) ($script['handle'] ?? '') . ' ' . (string) ($script['id'] ?? ''));
        $identity_token = preg_replace('/[^a-z0-9]+/', '', $identity);
        return '' !== $identity_token && false !== strpos($identity_token, $owner_token);
    }

    private function runtime_js_scan_script_defines_global_function($content, $global)
    {
        $content = (string) $content;
        $global = trim((string) $global);
        if ('' === $content || '' === $global || $this->runtime_js_scan_is_generic_token($global)) {
            return false;
        }

        $quoted = preg_quote($global, '/');
        $assignment_rhs = '(?:function\b|async\s+function\b|\([^\)]{0,240}\)\s*=>|[A-Za-z_$][A-Za-z0-9_$]*\s*=>)';
        if (preg_match('/\bfunction\s+' . $quoted . '\s*\(/i', $content)) {
            return true;
        }
        if (preg_match('/(?:window|globalThis|self)\s*(?:\.\s*' . $quoted . '|\[\s*["\']' . $quoted . '["\']\s*\])\s*=\s*' . $assignment_rhs . '/i', $content)) {
            return true;
        }
        if (preg_match('/(?:^|[;{}])\s*(?:var\s+)?' . $quoted . '\s*=\s*' . $assignment_rhs . '/i', $content)) {
            return true;
        }
        return false;
    }

    private function runtime_js_scan_inventory_index_for_script(array $needle, array $scripts)
    {
        $identity = $this->runtime_js_scan_unique_loaded_script_identity($needle);
        if ('' === $identity) {
            return -1;
        }
        foreach ($scripts as $index => $script) {
            if (!is_array($script)) {
                continue;
            }
            if ($identity === $this->runtime_js_scan_unique_loaded_script_identity($script)) {
                return (int) $index;
            }
        }
        return -1;
    }

    private function runtime_js_scan_computed_window_consumer_scripts($variable, $source, $message, $detail, array $scripts)
    {
        $variable = trim((string) $variable);
        if ('' === $variable) {
            return array();
        }
        $call_pattern = '/window\s*\[\s*' . preg_quote($variable, '/') . '\s*\]\s*\(/i';
        $matches = array();
        $seen = array();
        $push_if_proven = function ($script) use (&$matches, &$seen, $call_pattern) {
            if (!is_array($script)) {
                return;
            }
            $content = $this->runtime_js_scan_script_content($script);
            if ('' === $content || !preg_match($call_pattern, $content)) {
                return;
            }
            $identity = $this->runtime_js_scan_unique_loaded_script_identity($script);
            if ('' === $identity || isset($seen[$identity])) {
                return;
            }
            $seen[$identity] = true;
            $matches[] = $script;
        };

        foreach ($this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts) as $consumer) {
            $push_if_proven($consumer);
        }
        if (1 === count($matches)) {
            return $matches;
        }

        // DevTools may rewrite dynamically restored scripts as "VM123 main.js".
        // When the stack source is therefore only a generic basename, inspect
        // loaded local scripts for the exact computed-window call from the error.
        // One unique source-content match is accepted; ambiguity remains review-only.
        $stack_basenames = array();
        $stack_text = (string) $source . "\n" . (string) $message . "\n" . (string) $detail;
        if (preg_match_all('/(?:VM\d+\s+)?([A-Za-z0-9._-]+\.m?js)(?:\?[^\s\)]*)?(?::\d+(?::\d+)?)?/i', $stack_text, $basename_matches)) {
            foreach ((array) ($basename_matches[1] ?? array()) as $basename) {
                $basename = strtolower(trim((string) $basename));
                if ('' !== $basename) {
                    $stack_basenames[$basename] = true;
                }
            }
        }

        $matches = array();
        $seen = array();
        foreach ($scripts as $script) {
            if (!is_array($script) || empty($script['src'])) {
                continue;
            }
            $base = strtolower($this->runtime_js_scan_basename_from_source((string) $script['src']));
            if (!empty($stack_basenames) && ('' === $base || !isset($stack_basenames[$base]))) {
                continue;
            }
            $push_if_proven($script);
            if (count($matches) > 1) {
                return array();
            }
        }

        return 1 === count($matches) ? $matches : array();
    }

    private function runtime_js_scan_add_computed_window_global_provider_suggestion(&$suggestions, &$seen, $source, $message, $detail, array $scripts, array $exclusions)
    {
        $variables = $this->runtime_js_scan_extract_computed_window_call_variables_from_error($message, $detail);
        if (1 !== count($variables)) {
            return false;
        }

        $variable = (string) $variables[0];
        $consumers = $this->runtime_js_scan_computed_window_consumer_scripts($variable, $source, $message, $detail, $scripts);
        if (1 !== count($consumers)) {
            return false;
        }
        $consumer = (array) $consumers[0];
        $consumer_src = (string) ($consumer['src'] ?? '');
        $owner = $this->runtime_js_scan_owner_from_script_source($consumer_src);
        if (empty($owner)) {
            return false;
        }

        $globals = array();
        foreach ($scripts as $script) {
            if (!is_array($script) || !$this->runtime_js_scan_script_matches_owner_context($script, $owner)) {
                continue;
            }
            $content = $this->runtime_js_scan_script_content($script);
            foreach ($this->runtime_js_scan_dynamic_callback_globals_from_text($content) as $global) {
                $global = trim((string) $global);
                if ('' === $global || $this->runtime_js_scan_is_generic_token($global)) {
                    continue;
                }
                $globals[strtolower($global)] = $global;
            }
        }
        if (1 !== count($globals)) {
            return false;
        }
        $global = (string) reset($globals);

        $providers = array();
        $provider_seen = array();
        foreach ($scripts as $script) {
            if (!is_array($script) || !$this->runtime_js_scan_script_matches_owner_context($script, $owner)) {
                continue;
            }
            $content = $this->runtime_js_scan_script_content($script);
            if (!$this->runtime_js_scan_script_defines_global_function($content, $global)) {
                continue;
            }
            $identity = $this->runtime_js_scan_unique_loaded_script_identity($script);
            if ('' === $identity || isset($provider_seen[$identity])) {
                continue;
            }
            $provider_seen[$identity] = true;
            $providers[] = $script;
        }
        if (1 !== count($providers)) {
            return false;
        }

        $provider = (array) $providers[0];
        $provider_index = $this->runtime_js_scan_inventory_index_for_script($provider, $scripts);
        $consumer_index = $this->runtime_js_scan_inventory_index_for_script($consumer, $scripts);
        if ($provider_index < 0 || $consumer_index < 0 || $provider_index >= $consumer_index) {
            return false;
        }

        $provider_strategy = $this->runtime_js_scan_script_effective_strategy($provider);
        $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($consumer);
        $preferred_target = $this->runtime_js_scan_declared_dependency_preferred_target($provider_strategy, $consumer_strategy, false);
        if ('' === $preferred_target) {
            return false;
        }

        $provider_suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($provider);
        if ('' === $provider_suggestion) {
            return false;
        }

        $provider_name = sanitize_text_field((string) ($provider['handle'] ?? $provider['id'] ?? $provider_suggestion));
        $consumer_name = sanitize_text_field((string) ($consumer['handle'] ?? $consumer['id'] ?? $consumer_src));
        $delay_suggestion = $this->runtime_js_scan_delay_consumer_suggestion($provider_strategy, $consumer_strategy, $consumer);
        $reason = 'The browser reports a computed global call window[' . sanitize_text_field($variable) . ']() as unavailable. Runtime Scan proved the exact stack consumer "' . $consumer_name . '" contains that computed call, found one callback/function setting in the same plugin/theme context resolving the runtime global to "' . sanitize_text_field($global) . '", and found exactly one earlier loaded companion that actually defines that global function: "' . $provider_name . '". The provider executes as ' . $provider_strategy . ' while the consumer executes as ' . $consumer_strategy . '. When the provider is delayed and the consumer is deferred, the least-invasive repair is to delay the proven consumer too; if that does not resolve the error, the existing provider promotion safeguards remain available.';

        $this->runtime_js_scan_add_suggestion(
            $suggestions,
            $seen,
            $provider_suggestion,
            'computed window global provider ' . $global,
            (string) ($provider['src'] ?? $provider['id'] ?? ''),
            trim((string) $message . "\n" . (string) $detail),
            $reason,
            $exclusions,
            'recommended',
            $preferred_target,
            true,
            null,
            $delay_suggestion
        );
        return true;
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

    private function runtime_js_scan_jquery_alias_scope_proves_pattern($content, $alias, $required_pattern)
    {
        $content = (string) $content;
        $alias = trim((string) $alias);
        $required_pattern = (string) $required_pattern;
        if ('' === $content || '' === $alias || '' === $required_pattern || !preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $alias)) {
            return false;
        }

        if (in_array($alias, array('$', 'jQuery'), true)) {
            return (bool) preg_match($required_pattern, $content);
        }

        $alias_regex = preg_quote($alias, '/');
        if (preg_match('/(?:^|[;,({])\s*(?:(?:var|let|const)\s+)?' . $alias_regex . '\s*=\s*(?:(?:window|globalThis)\s*\.\s*)?jQuery\b/i', $content)
            && preg_match($required_pattern, $content)) {
            return true;
        }

        // Prove minifier/IIFE aliases from the actual invocation contract, e.g.
        // !function(M){ M(...).plugin(); }(jQuery). The required pattern must
        // occur inside that exact function body before the alias is accepted.
        if (!preg_match_all('/(?:^|[!;(,~+\-])\s*function(?:\s+[A-Za-z_$][A-Za-z0-9_$]*)?\s*\(([^)]*)\)\s*\{/m', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $content_length = strlen($content);
        foreach ($matches as $match) {
            $params_text = (string) ($match[1][0] ?? '');
            $params = array_values(array_map('trim', explode(',', $params_text)));
            $alias_index = array_search($alias, $params, true);
            if (false === $alias_index) {
                continue;
            }

            $full_match = (string) ($match[0][0] ?? '');
            $match_offset = (int) ($match[0][1] ?? -1);
            $relative_brace = strrpos($full_match, '{');
            if ($match_offset < 0 || false === $relative_brace) {
                continue;
            }
            $brace_offset = $match_offset + $relative_brace;
            $block = $this->runtime_js_scan_extract_js_brace_block(
                $content,
                $brace_offset,
                max(6000, $content_length - $brace_offset + 1)
            );
            if ('' === $block) {
                // Additive fallback only: preserve the original extractor for
                // every previously-working fixer and use the lexical scanner
                // solely when this alias/IIFE proof could not close the block.
                $block = $this->runtime_js_scan_extract_js_brace_block_lexical(
                    $content,
                    $brace_offset,
                    max(6000, $content_length - $brace_offset + 1)
                );
            }
            if ('' === $block || !preg_match($required_pattern, $block)) {
                continue;
            }

            $cursor = $brace_offset + strlen($block);
            while ($cursor < $content_length && (ctype_space($content[$cursor]) || ')' === $content[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $content_length || '(' !== $content[$cursor]) {
                continue;
            }

            $args = $this->runtime_js_scan_extract_js_call_arguments($content, $cursor, 4000);
            if (!isset($args[$alias_index])) {
                continue;
            }
            $argument = preg_replace('/\s+/', '', (string) $args[$alias_index]);
            if (preg_match('/^(?:(?:window|globalThis)\.)?jQuery$/i', (string) $argument)) {
                return true;
            }

            // Additive compatibility proof for jQuery-first wrappers that
            // explicitly fall back to Zepto, e.g. iCheck:
            // (function(k){ k.fn.iCheck = ...; })(window.jQuery || window.Zepto);
            // Keep this narrow: jQuery must be the first operand and Zepto
            // must be the only fallback, so generic OR expressions cannot
            // become jQuery-provider proof accidentally.
            if (preg_match('/^(?:(?:window|globalThis)\.)?jQuery\|\|(?:(?:window|globalThis)\.)?Zepto$/i', (string) $argument)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prove a two-stage UMD/factory jQuery plugin provider.
     *
     * Common minified libraries do not register methods through a direct
     * jQuery/$ alias. Instead they pass a factory function into a UMD wrapper,
     * and that wrapper later invokes the factory with jQuery, for example:
     *
     * !function(factory){ factory(jQuery); }(function($){ $.fn.plugin = ...; });
     *
     * This proof is intentionally narrow: the outer wrapper must demonstrably
     * inject jQuery into one of its parameters, the corresponding invocation
     * argument must be a function expression, and that factory parameter must
     * directly register the requested jQuery.fn method inside its own body.
     */
    private function runtime_js_scan_jquery_umd_factory_provider_is_proven($content, $method)
    {
        $content = (string) $content;
        $method = trim((string) $method);
        if ('' === $content || '' === $method) {
            return false;
        }

        if (!preg_match_all('/(?:^|[!;(,~+\\-])\\s*function(?:\\s+[A-Za-z_$][A-Za-z0-9_$]*)?\\s*\\(([^)]*)\\)\\s*\\{/m', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $content_length = strlen($content);
        $method_regex = preg_quote($method, '/');
        foreach ($matches as $match) {
            $params_text = (string) ($match[1][0] ?? '');
            $params = array_values(array_map('trim', explode(',', $params_text)));
            if (empty($params)) {
                continue;
            }

            $full_match = (string) ($match[0][0] ?? '');
            $match_offset = (int) ($match[0][1] ?? -1);
            $relative_brace = strrpos($full_match, '{');
            if ($match_offset < 0 || false === $relative_brace) {
                continue;
            }
            $brace_offset = $match_offset + $relative_brace;
            $wrapper_block = $this->runtime_js_scan_extract_js_brace_block(
                $content,
                $brace_offset,
                max(6000, min(131072, $content_length - $brace_offset + 1))
            );
            if ('' === $wrapper_block) {
                $wrapper_block = $this->runtime_js_scan_extract_js_brace_block_lexical(
                    $content,
                    $brace_offset,
                    max(6000, min(131072, $content_length - $brace_offset + 1))
                );
            }
            if ('' === $wrapper_block) {
                continue;
            }

            $cursor = $brace_offset + strlen($wrapper_block);
            while ($cursor < $content_length && (ctype_space($content[$cursor]) || ')' === $content[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $content_length || '(' !== $content[$cursor]) {
                continue;
            }

            $args = $this->runtime_js_scan_extract_js_call_arguments(
                $content,
                $cursor,
                max(8000, min(262144, $content_length - $cursor + 1))
            );
            if (empty($args)) {
                continue;
            }

            foreach ($params as $factory_index => $factory_alias) {
                if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', (string) $factory_alias) || !isset($args[$factory_index])) {
                    continue;
                }

                $factory_regex = preg_quote((string) $factory_alias, '/');
                $injects_jquery = (bool) preg_match(
                    '/(?:^|[^A-Za-z0-9_$])' . $factory_regex . '\\s*\\(\\s*(?:(?:window|globalThis)\\s*\\.\\s*)?jQuery\\b/i',
                    $wrapper_block
                );
                if (!$injects_jquery) {
                    $injects_jquery = (bool) preg_match(
                        '/(?:^|[^A-Za-z0-9_$])' . $factory_regex . '\\s*\\(\\s*require\\s*\\(\\s*["\\\']jquery["\\\']\\s*\\)/i',
                        $wrapper_block
                    );
                }
                if (!$injects_jquery) {
                    $injects_jquery = (bool) preg_match(
                        '/define\\s*\\(\\s*\\[\\s*["\\\']jquery["\\\']\\s*\\]\\s*,\\s*' . $factory_regex . '\\b/i',
                        $wrapper_block
                    );
                }
                if (!$injects_jquery) {
                    continue;
                }

                $factory_argument = trim((string) $args[$factory_index]);
                if (!preg_match('/^function(?:\\s+[A-Za-z_$][A-Za-z0-9_$]*)?\\s*\\(([^)]*)\\)\\s*\\{/s', $factory_argument, $factory_match, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $factory_params = array_values(array_map('trim', explode(',', (string) ($factory_match[1][0] ?? ''))));
                $jquery_alias = isset($factory_params[0]) ? (string) $factory_params[0] : '';
                if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $jquery_alias)) {
                    continue;
                }

                $factory_full = (string) ($factory_match[0][0] ?? '');
                $factory_open = strrpos($factory_full, '{');
                if (false === $factory_open) {
                    continue;
                }
                $factory_block = $this->runtime_js_scan_extract_js_brace_block(
                    $factory_argument,
                    $factory_open,
                    max(6000, min(262144, strlen($factory_argument) - $factory_open + 1))
                );
                if ('' === $factory_block) {
                    $factory_block = $this->runtime_js_scan_extract_js_brace_block_lexical(
                        $factory_argument,
                        $factory_open,
                        max(6000, min(262144, strlen($factory_argument) - $factory_open + 1))
                    );
                }
                if ('' === $factory_block) {
                    continue;
                }

                $jquery_alias_regex = preg_quote($jquery_alias, '/');
                $assignment_pattern = '/(?:^|[^A-Za-z0-9_$])' . $jquery_alias_regex
                    . '\\s*\\.\\s*fn\\s*(?:\\.\\s*' . $method_regex
                    . '|\\[\\s*["\\\']' . $method_regex . '["\\\']\\s*\\])\\s*=/i';
                if (preg_match($assignment_pattern, $factory_block)) {
                    return true;
                }

                $extend_pattern = '/(?:^|[^A-Za-z0-9_$])' . $jquery_alias_regex
                    . '\\s*\\.\\s*fn\\s*\\.\\s*extend\\s*\\(\\s*\\{[\\s\\S]{0,12000}?(?:^|[,{}])\\s*["\\\']?'
                    . $method_regex . '["\\\']?\\s*:/i';
                if (preg_match($extend_pattern, $factory_block)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function runtime_js_scan_jquery_alias_consumer_is_proven($content, $alias, $method)
    {
        $content = (string) $content;
        $alias = trim((string) $alias);
        $method = trim((string) $method);
        if ('' === $content || '' === $alias || '' === $method) {
            return false;
        }

        $alias_regex = preg_quote($alias, '/');
        $method_regex = preg_quote($method, '/');
        $call_pattern = '/(?:^|[^A-Za-z0-9_$])' . $alias_regex
            . '\s*\([^;\n]{0,1200}?\)\s*(?:\.\s*' . $method_regex
            . '|\[\s*["\']' . $method_regex . '["\']\s*\])\s*\(/i';

        return $this->runtime_js_scan_jquery_alias_scope_proves_pattern($content, $alias, $call_pattern);
    }

    private function runtime_js_scan_jquery_alias_provider_is_proven($content, $alias, $method)
    {
        $content = (string) $content;
        $alias = trim((string) $alias);
        $method = trim((string) $method);
        if ('' === $content || '' === $alias || '' === $method) {
            return false;
        }

        $alias_regex = preg_quote($alias, '/');
        $method_regex = preg_quote($method, '/');
        $assignment_pattern = '/(?:^|[^A-Za-z0-9_$])' . $alias_regex
            . '\s*\.\s*fn\s*(?:\.\s*' . $method_regex
            . '|\[\s*["\']' . $method_regex . '["\']\s*\])\s*=/i';
        if ($this->runtime_js_scan_jquery_alias_scope_proves_pattern($content, $alias, $assignment_pattern)) {
            return true;
        }

        $extend_pattern = '/(?:^|[^A-Za-z0-9_$])' . $alias_regex
            . '\s*\.\s*fn\s*\.\s*extend\s*\(\s*\{[\s\S]{0,5000}?(?:^|[,{}])\s*["\']?'
            . $method_regex . '["\']?\s*:/i';
        return $this->runtime_js_scan_jquery_alias_scope_proves_pattern($content, $alias, $extend_pattern);
    }

    private function runtime_js_scan_jquery_file_defines_method($content, $method, $identity = '')
    {
        $method = trim((string) $method);
        $content = (string) $content;
        if ('' === $method) {
            return false;
        }

        // Filename/handle identity is only a fallback when source text is not
        // available. When code is available, prove the actual jQuery.fn method
        // registration so similarly named libraries cannot become providers.
        if ('' === $content) {
            return $this->runtime_js_scan_jquery_provider_identity_matches_method((string) $identity, $method);
        }

        $method_regex = preg_quote($method, '/');
        foreach (array('jQuery', '$') as $explicit_alias) {
            if ($this->runtime_js_scan_jquery_alias_provider_is_proven($content, $explicit_alias, $method)) {
                return true;
            }
        }

        $candidate_aliases = array();
        if (preg_match_all('/([A-Za-z_$][A-Za-z0-9_$]*)\s*\.\s*fn\s*(?:\.\s*' . $method_regex . '|\[\s*["\']' . $method_regex . '["\']\s*\])\s*=/i', $content, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $alias) {
                $candidate_aliases[$alias] = true;
            }
        }
        if (preg_match_all('/([A-Za-z_$][A-Za-z0-9_$]*)\s*\.\s*fn\s*\.\s*extend\s*\(\s*\{/i', $content, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $alias) {
                $candidate_aliases[$alias] = true;
            }
        }

        foreach (array_keys($candidate_aliases) as $alias) {
            if ($this->runtime_js_scan_jquery_alias_provider_is_proven($content, $alias, $method)) {
                return true;
            }
        }

        // Additive UMD/factory fallback for libraries such as Slick and
        // Magnific Popup. Existing direct aliases and IIFE aliases remain the
        // first proof paths above.
        if ($this->runtime_js_scan_jquery_umd_factory_provider_is_proven($content, $method)) {
            return true;
        }

        /*
         * Additive jQuery-Bridget proof for bundled libraries such as Flickity.
         * jQuery-Bridget installs the plugin dynamically through fn[name], so a
         * literal fn.flickity assignment does not exist in the provider source.
         * Keep every existing provider proof above authoritative and use this
         * only as a final source-level extension when the requested method name
         * is passed literally to the bridge registration API.
         *
         * An explicit jQuery/$ receiver is sufficient by itself. A minified
         * receiver (for example a.bridget("flickity", Flickity)) is accepted
         * only when the same bundle also contains jQuery-Bridget implementation
         * evidence. This avoids turning an unrelated object named "bridget" into
         * a jQuery-plugin provider.
         */
        if ($this->runtime_js_scan_jquery_bridget_provider_is_proven($content, $method)) {
            return true;
        }

        return false;
    }

    private function runtime_js_scan_jquery_bridget_provider_is_proven($content, $method)
    {
        $content = (string) $content;
        $method = trim((string) $method);
        if ('' === $content || '' === $method) {
            return false;
        }

        $method_regex = preg_quote($method, '/');
        $literal_method = '["\']' . $method_regex . '["\']';

        // Strongest form: the browser library calls the bridge on jQuery/$
        // directly with the exact missing plugin method name.
        if (preg_match('/(?:^|[^A-Za-z0-9_$])(?:jQuery|\$)\s*\.\s*bridget\s*\(\s*' . $literal_method . '\s*,/i', $content)) {
            return true;
        }

        // Minified bundles commonly alias jQuery before calling .bridget().
        // Require same-file bridge implementation evidence before accepting an
        // arbitrary alias receiver as proof.
        if (!preg_match('/(?:^|[^A-Za-z0-9_$])[A-Za-z_$][A-Za-z0-9_$]*\s*\.\s*bridget\s*\(\s*' . $literal_method . '\s*,/i', $content)) {
            return false;
        }

        if (false !== stripos($content, 'jquery-bridget') || false !== stripos($content, 'jQueryBridget')) {
            return true;
        }

        // Minified jQuery-Bridget core assigns the generated plugin through
        // fn[name] and exposes the bridge function on the jQuery alias. Require
        // both structural signals when the readable module name was stripped.
        return (bool) (
            preg_match('/\.\s*fn\s*\[\s*[A-Za-z_$][A-Za-z0-9_$]*\s*\]\s*=\s*function\b/i', $content)
            && preg_match('/\.\s*bridget\s*=\s*[A-Za-z_$][A-Za-z0-9_$]*/i', $content)
        );
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
            $providers[] = array(
                'src'    => $src,
                'id'     => $id,
                'origin' => 'page-inventory',
                'script' => $script,
            );
            if (count($providers) >= 8) {
                break;
            }
        }

        return $providers;
    }

    private function runtime_js_scan_find_jquery_plugin_inline_consumer_scripts($method, array $scripts)
    {
        $method = trim((string) $method);
        if ('' === $method || empty($scripts)) {
            return array();
        }

        $consumers = array();
        $seen = array();
        foreach ($scripts as $index => $script) {
            if (!is_array($script)) {
                continue;
            }

            // This resolver is for document inline blocks only. External scripts
            // are resolved from the browser stack/source inventory above. An
            // inline block has no public src, but Runtime Scan captures its exact
            // text and execution strategy, which is enough to use it as causal
            // evidence without ever turning the inline block into an append target.
            $src = $this->runtime_js_scan_clean_console_candidate((string) ($script['src'] ?? ''));
            if ('' !== $src) {
                continue;
            }
            $content = $this->runtime_js_scan_script_content($script);
            if ('' === $content || !$this->runtime_js_scan_jquery_file_uses_method($content, $method)) {
                continue;
            }

            $identity = $this->runtime_js_scan_unique_loaded_script_identity($script);
            if ('' === $identity) {
                $identity = 'inline:' . md5(
                    (string) $index . '|'
                    . (string) ($script['id'] ?? '') . '|'
                    . (string) ($script['handle'] ?? '') . '|'
                    . $content
                );
            }
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $consumers[] = $script;
            if (count($consumers) >= 8) {
                break;
            }
        }

        return $consumers;
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
            $related_id = $this->runtime_js_scan_related_external_id_for_inline_id($inline_id);
            if ('' !== $related_id) {
                $related = $this->runtime_js_scan_find_script_by_id($scripts, $related_id);
                if (!empty($related)) {
                    // Inline before/after blocks do not have an independent load
                    // strategy. Target the owning enqueued script once instead of
                    // recommending both the inline companion and its parent.
                    $related_src = isset($related['src']) ? (string) $related['src'] : '';
                    $related_fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($related_src, 4);
                    if ('' !== $related_fragment) {
                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, $related_fragment, 'inline stack-frame parent script', $related_src, $message, $reason, $exclusions, $confidence);
                    } else {
                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, $related_id, 'inline stack-frame parent handle/id', $related_src, $message, $reason, $exclusions, $confidence, '', true);
                    }
                    continue;
                }
            }

            // Only fall back to the literal inline id when its parent cannot be
            // resolved from the scanned page inventory.
            $script = $this->runtime_js_scan_find_script_by_id($scripts, $inline_id);
            $source = !empty($script['src']) ? (string) $script['src'] : $inline_id;
            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $inline_id, 'unresolved inline stack-frame handle/id', $source, $message, $reason, $exclusions, $confidence, '', true);
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

    private function runtime_js_scan_remove_redundant_persistent_source_suggestions(&$suggestions, array $sources)
    {
        $targets = array();
        foreach ($sources as $source) {
            $source = strtolower($this->runtime_js_scan_clean_console_candidate((string) $source));
            if ('' !== $source) {
                $targets[$source] = true;
            }
        }
        if (empty($targets)) {
            return;
        }

        $suggestions = array_values(array_filter($suggestions, function ($item) use ($targets) {
            if (!is_array($item) || 'persistent exact runtime source' !== (string) ($item['symbol'] ?? '')) {
                return true;
            }
            $item_source = strtolower($this->runtime_js_scan_clean_console_candidate((string) ($item['definingScriptUrl'] ?? '')));
            return '' === $item_source || !isset($targets[$item_source]);
        }));
    }

    private function runtime_js_scan_remove_resolved_jquery_plugin_generic_fallbacks(&$suggestions, $method, $source, $message, $detail, array $consumer = array())
    {
        $method = strtolower(trim((string) $method));
        if ('' === $method || empty($suggestions)) {
            return;
        }

        $error_sources = array();
        $source_seen = array();
        $push_source = function ($candidate) use (&$error_sources, &$source_seen) {
            $candidate = $this->runtime_js_scan_clean_console_candidate((string) $candidate);
            if ('' === $candidate) {
                return;
            }
            $key = strtolower($candidate);
            if (isset($source_seen[$key])) {
                return;
            }
            $source_seen[$key] = true;
            $error_sources[] = $candidate;
        };

        $push_source($source);
        if (!empty($consumer['src'])) {
            $push_source((string) $consumer['src']);
        }
        foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
            $push_source($candidate);
        }
        foreach ($this->runtime_js_scan_console_sources_from_text((string) $message . "\n" . (string) $detail) as $candidate) {
            $push_source($candidate);
        }

        if (empty($error_sources)) {
            return;
        }

        // Additive cleanup only: once the jQuery resolver has proven one exact
        // loaded provider and one exact loaded consumer with an actionable order
        // conflict, discard only the older generic source fallbacks generated for
        // this same missing jQuery method and this same error stack. Other fixer
        // classes, other methods, and unrelated runtime errors remain untouched.
        $suggestions = array_values(array_filter($suggestions, function ($item) use ($method, $error_sources) {
            if (!is_array($item)) {
                return true;
            }

            $symbol = (string) ($item['symbol'] ?? '');
            if (!in_array($symbol, array('runtime error stack source', 'persistent exact runtime source'), true)) {
                return true;
            }

            $sample = (string) ($item['sample'] ?? '');
            $same_method = false;
            foreach ($this->runtime_js_scan_extract_jquery_plugin_calls_from_error($sample, '') as $call) {
                if ($method === strtolower((string) ($call['method'] ?? ''))) {
                    $same_method = true;
                    break;
                }
            }
            if (!$same_method) {
                return true;
            }

            $candidate = (string) ($item['suggestedExclusion'] ?? '');
            $item_source = (string) ($item['definingScriptUrl'] ?? '');
            foreach ($error_sources as $error_source) {
                if ('' !== $candidate && $this->runtime_js_scan_candidate_matches_error_source($candidate, $error_source)) {
                    return false;
                }
                if ('' !== $item_source && $this->runtime_js_scan_script_matches_candidate(array('src' => $item_source), $error_source)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function runtime_js_scan_register_resolved_jquery_plugin_context($method, $source, $message, $detail, array $consumer = array())
    {
        $method = strtolower(trim((string) $method));
        if ('' === $method) {
            return;
        }

        $sources = array();
        $seen = array();
        $push = function ($candidate) use (&$sources, &$seen) {
            $candidate = $this->runtime_js_scan_clean_console_candidate((string) $candidate);
            if ('' === $candidate) {
                return;
            }
            $key = strtolower($candidate);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $sources[] = $candidate;
        };

        $push($source);
        if (!empty($consumer['src'])) {
            $push((string) $consumer['src']);
        }
        foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
            $push($candidate);
        }
        foreach ($this->runtime_js_scan_console_sources_from_text((string) $message . "\n" . (string) $detail) as $candidate) {
            $push($candidate);
        }

        if (empty($sources)) {
            return;
        }

        $this->runtime_js_scan_resolved_jquery_plugin_contexts[] = array(
            'method' => $method,
            'sources' => $sources,
        );
    }

    private function runtime_js_scan_remove_late_resolved_jquery_plugin_fallbacks(array $suggestions)
    {
        if (empty($suggestions) || empty($this->runtime_js_scan_resolved_jquery_plugin_contexts)) {
            return $suggestions;
        }

        return array_values(array_filter($suggestions, function ($item) {
            if (!is_array($item)) {
                return true;
            }

            $symbol = (string) ($item['symbol'] ?? '');
            if (!in_array($symbol, array('runtime error stack source', 'persistent exact runtime source'), true)) {
                return true;
            }

            $sample = (string) ($item['sample'] ?? '');
            $methods = array();
            foreach ($this->runtime_js_scan_extract_jquery_plugin_calls_from_error($sample, '') as $call) {
                $method = strtolower((string) ($call['method'] ?? ''));
                if ('' !== $method) {
                    $methods[$method] = true;
                }
            }
            if (empty($methods)) {
                return true;
            }

            $candidate = (string) ($item['suggestedExclusion'] ?? '');
            $item_source = (string) ($item['definingScriptUrl'] ?? '');

            foreach ($this->runtime_js_scan_resolved_jquery_plugin_contexts as $context) {
                if (!is_array($context)) {
                    continue;
                }
                $method = strtolower((string) ($context['method'] ?? ''));
                if ('' === $method || empty($methods[$method])) {
                    continue;
                }

                foreach ((array) ($context['sources'] ?? array()) as $error_source) {
                    $error_source = (string) $error_source;
                    if ('' !== $candidate && $this->runtime_js_scan_candidate_matches_error_source($candidate, $error_source)) {
                        return false;
                    }
                    if ('' !== $item_source && $this->runtime_js_scan_script_matches_candidate(array('src' => $item_source), $error_source)) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    private function runtime_js_scan_explicit_jquery_consumer_methods($content)
    {
        $content = (string) $content;
        if ('' === $content) {
            return array();
        }

        $methods = array();
        if (!preg_match_all('/(?:jQuery|\$)\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return array();
        }

        foreach ((array) ($matches[0] ?? array()) as $match) {
            $offset = (int) ($match[1] ?? -1);
            if ($offset < 0) {
                continue;
            }
            $limit = min(strlen($content), $offset + 1800);
            $semicolon = strpos($content, ';', $offset);
            if (false !== $semicolon && $semicolon < $limit) {
                $limit = $semicolon + 1;
            }
            $scope = substr($content, $offset, max(0, $limit - $offset));
            if (!preg_match_all('/\.\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $scope, $method_matches)) {
                continue;
            }
            foreach ((array) ($method_matches[1] ?? array()) as $method) {
                $method = trim((string) $method);
                if ('' === $method) {
                    continue;
                }
                $methods[strtolower($method)] = $method;
                if (count($methods) >= 48) {
                    break 2;
                }
            }
        }

        return array_values($methods);
    }

    private function runtime_js_scan_direct_jquery_methods_defined_in_content($content)
    {
        $content = (string) $content;
        if ('' === $content) {
            return array();
        }

        $methods = array();
        if (preg_match_all('/[A-Za-z_$][A-Za-z0-9_$]*\s*\.\s*fn\s*(?:\.\s*([A-Za-z_$][A-Za-z0-9_$]*)|\[\s*["\']([^"\']+)["\']\s*\])\s*=/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $method = trim((string) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')));
                if ('' === $method || !preg_match('/^[A-Za-z_$][A-Za-z0-9_$-]*$/', $method)) {
                    continue;
                }
                $methods[strtolower($method)] = $method;
                if (count($methods) >= 64) {
                    break;
                }
            }
        }

        return array_values($methods);
    }

    private function runtime_js_scan_parallel_jquery_companion_providers($primary_method, array $consumer_script, array $scripts)
    {
        $primary_method = strtolower(trim((string) $primary_method));
        $consumer_content = $this->runtime_js_scan_script_content($consumer_script);
        $consumer_methods = array();
        foreach ($this->runtime_js_scan_explicit_jquery_consumer_methods($consumer_content) as $method) {
            $key = strtolower((string) $method);
            if ('' !== $key && $key !== $primary_method) {
                $consumer_methods[$key] = (string) $method;
            }
        }
        if (empty($consumer_methods)) {
            return array();
        }

        $consumer_index = $this->runtime_js_scan_inventory_index_for_exact_script($consumer_script, $scripts);
        if ($consumer_index <= 0) {
            return array();
        }

        $providers_by_method = array();
        $consumer_identity = $this->runtime_js_scan_unique_loaded_script_identity($consumer_script);
        foreach ($scripts as $index => $script) {
            if (!is_array($script) || (int) $index >= $consumer_index) {
                break;
            }
            if ($consumer_identity === $this->runtime_js_scan_unique_loaded_script_identity($script)) {
                continue;
            }

            $content = $this->runtime_js_scan_script_content($script);
            if ('' === $content) {
                continue;
            }
            $defined_methods = $this->runtime_js_scan_direct_jquery_methods_defined_in_content($content);
            if (empty($defined_methods)) {
                continue;
            }

            foreach ($defined_methods as $defined_method) {
                $method_key = strtolower((string) $defined_method);
                if (!isset($consumer_methods[$method_key])) {
                    continue;
                }
                $method = (string) $consumer_methods[$method_key];
                if (!$this->runtime_js_scan_jquery_file_defines_method($content, $method, '')) {
                    continue;
                }
                $identity = $this->runtime_js_scan_unique_loaded_script_identity($script);
                if ('' === $identity) {
                    continue;
                }
                if (!isset($providers_by_method[$method_key])) {
                    $providers_by_method[$method_key] = array();
                }
                $providers_by_method[$method_key][$identity] = array(
                    'method' => $method,
                    'script' => $script,
                    'index'  => (int) $index,
                );
            }
        }

        $companions = array();
        $seen_scripts = array();
        foreach ($providers_by_method as $provider_map) {
            if (1 !== count($provider_map)) {
                continue;
            }
            $provider = reset($provider_map);
            $provider_script = isset($provider['script']) && is_array($provider['script']) ? $provider['script'] : array();
            if (empty($provider_script)) {
                continue;
            }
            $strategy = $this->runtime_js_scan_script_effective_strategy($provider_script);
            if (!in_array($strategy, array('delay', 'defer', 'async'), true)) {
                continue;
            }
            $identity = $this->runtime_js_scan_unique_loaded_script_identity($provider_script);
            if ('' === $identity || isset($seen_scripts[$identity])) {
                continue;
            }
            $seen_scripts[$identity] = true;
            $provider['strategy'] = $strategy;
            $companions[] = $provider;
            if (count($companions) >= 8) {
                break;
            }
        }

        return $companions;
    }

    private function runtime_js_scan_inventory_index_for_exact_script(array $target, array $scripts)
    {
        $identity = $this->runtime_js_scan_unique_loaded_script_identity($target);
        if ('' === $identity) {
            return -1;
        }
        foreach ($scripts as $index => $script) {
            if (!is_array($script)) {
                continue;
            }
            if ($identity === $this->runtime_js_scan_unique_loaded_script_identity($script)) {
                return (int) $index;
            }
        }
        return -1;
    }

    private function runtime_js_scan_add_jquery_plugin_dependency_suggestions(&$suggestions, &$seen, $method, $source, $message, $detail, array $exclusions, array $scripts = array(), array $filesystem_context = array(), array $proven_consumer_script = array())
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

        $page_providers = $this->runtime_js_scan_find_jquery_plugin_provider_scripts($method, $scripts);
        foreach ($page_providers as $provider) {
            $add_provider($provider);
        }

        // Filesystem discovery is useful evidence, but it becomes actionable only
        // when the exact public provider path is also present in this page's
        // browser inventory. Promote that exact loaded record so provider strategy
        // is read from the real page instead of treating a filesystem-only match as
        // automatically loaded. No filename/slug heuristics are used here.
        if (empty($providers)) {
            foreach ((array) ($filesystem_context['providers'] ?? array()) as $provider) {
                if (!is_array($provider) || empty($provider['src'])) {
                    continue;
                }
                foreach ($this->runtime_js_scan_exact_loaded_scripts_for_source((string) $provider['src'], $scripts) as $loaded_script) {
                    $add_provider(array(
                        'src'    => (string) ($loaded_script['src'] ?? $provider['src']),
                        'id'     => (string) ($loaded_script['id'] ?? ''),
                        'origin' => 'page-inventory',
                        'script' => $loaded_script,
                    ));
                }
            }
        }

        // If no exact loaded provider can be proven, keep the filesystem match as
        // review evidence. It must never become an automatic execution-order fix.
        if (empty($providers)) {
            foreach ((array) ($filesystem_context['providers'] ?? array()) as $provider) {
                $add_provider($provider);
            }
        }

        $consumers = array();
        $consumer_seen = array();
        $add_consumer = function ($candidate, $origin = 'runtime-stack') use (&$consumers, &$consumer_seen) {
            $script_record = array();
            if (is_array($candidate)) {
                $src = isset($candidate['src']) ? (string) $candidate['src'] : '';
                $fragment = isset($candidate['fragment']) ? (string) $candidate['fragment'] : '';
                $origin = isset($candidate['origin']) ? (string) $candidate['origin'] : (string) $origin;
                if (!empty($candidate['script']) && is_array($candidate['script'])) {
                    $script_record = $candidate['script'];
                }
            } else {
                $src = (string) $candidate;
                $fragment = '';
            }
            $src = $this->runtime_js_scan_clean_console_candidate($src);
            if ('' === $fragment && '' !== $src) {
                $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($src, 5);
            }
            $inline_key = '';
            if ('' === $fragment && !empty($script_record) && '' === $src) {
                $inline_text = $this->runtime_js_scan_script_content($script_record);
                if ('' !== $inline_text) {
                    $inline_key = 'inline:' . md5(
                        (string) ($script_record['id'] ?? '') . '|'
                        . (string) ($script_record['handle'] ?? '') . '|'
                        . $inline_text
                    );
                }
            }
            if ('' === $fragment && '' === $inline_key) {
                return;
            }
            $key = '' !== $fragment ? 'fragment:' . strtolower($fragment) : $inline_key;
            if (isset($consumer_seen[$key])) {
                return;
            }
            $consumer_seen[$key] = true;
            $consumers[] = array(
                'src'      => $src,
                'fragment' => $fragment,
                'origin'   => (string) $origin,
                'script'   => $script_record,
            );
        };

        if (!empty($proven_consumer_script)) {
            $add_consumer(
                array(
                    'src'    => (string) ($proven_consumer_script['src'] ?? ''),
                    'origin' => 'page-inventory',
                    'script' => $proven_consumer_script,
                ),
                'page-inventory'
            );
        }

        $source_candidates = empty($consumers)
            ? $this->runtime_js_scan_source_candidates_from_error($source, $message, $detail)
            : array();
        foreach ($source_candidates as $candidate) {
            // A stack frame is not sufficient evidence that a script consumes
            // the missing jQuery plugin method. Wrapper/runtime frames can sit
            // above the real caller. Only page-inventory scripts whose scanned
            // code actually calls .method() are accepted as direct consumers.
            $matches = $this->runtime_js_scan_find_scripts_by_source_hint($candidate, $scripts);
            foreach ($matches as $matched_script) {
                if (!$this->runtime_js_scan_jquery_file_uses_method($this->runtime_js_scan_script_content($matched_script), $method)) {
                    continue;
                }
                if (!empty($matched_script['src'])) {
                    $add_consumer(
                        array(
                            'src'    => (string) $matched_script['src'],
                            'origin' => 'page-inventory',
                            'script' => $matched_script,
                        ),
                        'page-inventory'
                    );
                }
            }
        }

        if (empty($consumers)) {
            foreach ($this->runtime_js_scan_find_jquery_plugin_inline_consumer_scripts($method, $scripts) as $inline_consumer) {
                $add_consumer(
                    array(
                        'src'    => '',
                        'origin' => 'page-inventory-inline',
                        'script' => $inline_consumer,
                    ),
                    'page-inventory-inline'
                );
            }
        }

        if (empty($consumers)) {
            foreach ((array) ($filesystem_context['consumers'] ?? array()) as $consumer) {
                $add_consumer($consumer, 'filesystem');
                if (!empty($consumers)) {
                    break;
                }
            }
        }

        // Explicit jQuery(...).method is-not-a-function failures belong to this
        // resolver class exclusively. If provider/consumer runtime evidence is
        // incomplete, keep the finding review-only here instead of falling
        // through to the generic "exclude the failing consumer" fallback.
        if (1 !== count($providers) || 1 !== count($consumers)) {
            $review_candidate = '';
            $review_source = '';
            if (!empty($consumers[0]['fragment'])) {
                $review_candidate = (string) $consumers[0]['fragment'];
                $review_source = (string) ($consumers[0]['src'] ?? '');
            } elseif (!empty($providers[0]['fragment']) || !empty($providers[0]['src']) || !empty($providers[0]['id'])) {
                $review_source = (string) ($providers[0]['src'] ?? '');
                $review_candidate = (string) ($providers[0]['fragment'] ?? '');
                if ('' === $review_candidate) {
                    $review_candidate = $this->runtime_js_scan_targeted_source_fragment_from_source($review_source, 5);
                }
                if ('' === $review_candidate) {
                    $review_candidate = (string) ($providers[0]['id'] ?? '');
                }
            }
            if ('' !== $review_candidate) {
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $review_candidate,
                    'jQuery plugin dependency review',
                    '' !== $review_source ? $review_source : $review_candidate,
                    $message,
                    'The browser explicitly reports that jQuery.fn.' . sanitize_text_field($method) . ' is unavailable, but Runtime Scan could not prove one loaded provider and one direct loaded consumer with an actionable execution-order conflict. Keep this inside the jQuery-plugin resolver as review evidence; do not blame or automatically exclude the failing consumer.',
                    $exclusions,
                    'recommended',
                    '',
                    false,
                    false
                );
            }
            return true;
        }

        $provider = $providers[0];
        $consumer = $consumers[0];
        $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
        $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
        $provider_fragment = isset($provider['fragment']) ? (string) $provider['fragment'] : '';
        if ('' === $provider_fragment) {
            $provider_fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($provider_src, 5);
        }
        $provider_candidate = '' !== $provider_fragment ? $provider_fragment : $provider_id;
        $consumer_fragment = isset($consumer['fragment']) ? (string) $consumer['fragment'] : '';

        $provider_origin = isset($provider['origin']) ? (string) $provider['origin'] : '';
        $consumer_origin = isset($consumer['origin']) ? (string) $consumer['origin'] : '';
        $consumer_is_loaded = in_array($consumer_origin, array('page-inventory', 'page-inventory-inline'), true);
        if ('' === $provider_candidate || 'page-inventory' !== $provider_origin || !$consumer_is_loaded) {
            $review_candidate = '' !== $consumer_fragment ? $consumer_fragment : $provider_candidate;
            $review_source = '' !== (string) ($consumer['src'] ?? '') ? (string) $consumer['src'] : $provider_src;
            if ('' !== $review_candidate) {
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $review_candidate,
                    'jQuery plugin dependency review',
                    '' !== $review_source ? $review_source : $review_candidate,
                    $message,
                    'The browser explicitly reports that jQuery.fn.' . sanitize_text_field($method) . ' is unavailable, but the provider/consumer pair is not both proven as loaded page-inventory scripts. Keep this as review evidence and do not convert the consumer into a generic compatibility exclusion.',
                    $exclusions,
                    'recommended',
                    '',
                    false,
                    false
                );
            }
            return true;
        }

        $provider_script = isset($provider['script']) && is_array($provider['script']) ? $provider['script'] : array();
        $consumer_script = isset($consumer['script']) && is_array($consumer['script']) ? $consumer['script'] : array();
        if (empty($provider_script) || empty($consumer_script)) {
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $provider_candidate,
                'jQuery plugin dependency review',
                '' !== $provider_src ? $provider_src : $provider_candidate,
                $message,
                'Runtime Scan found the loaded jQuery.fn.' . sanitize_text_field($method) . ' provider and direct consumer, but the exact page-inventory records are unavailable for one side of the pair. Keep this as review evidence instead of re-resolving either script through fuzzy source matching.',
                $exclusions,
                'recommended',
                '',
                false,
                false
            );
            return true;
        }

        // Provider and consumer were already proven as exact loaded page-inventory
        // records above. Read their execution strategies directly from those
        // records; do not discard exact identity and perform a second fuzzy
        // candidate lookup that can merge unrelated handles/basenames.
        $provider_strategy = $this->runtime_js_scan_script_effective_strategy($provider_script);
        $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($consumer_script);
        $preferred_target = $this->runtime_js_scan_declared_dependency_preferred_target($provider_strategy, $consumer_strategy, false);
        if ('' === $preferred_target) {
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $provider_candidate,
                'jQuery plugin dependency review',
                '' !== $provider_src ? $provider_src : $provider_candidate,
                $message,
                'Runtime Scan found the loaded jQuery.fn.' . sanitize_text_field($method) . ' provider and direct consumer, but their scanned execution strategies do not prove that the provider runs too late. Keep this as review evidence and do not blame the consumer.',
                $exclusions,
                'recommended',
                '',
                false,
                false
            );
            return true;
        }

        $parallel_consumer_conflict = 'async' === $consumer_strategy
            && in_array($provider_strategy, array('delay', 'defer', 'async'), true)
            && 'exclusion' === $preferred_target;
        $consumer_candidate = $this->runtime_js_scan_dependency_suggestion_for_script($consumer_script);
        $parallel_companion_providers = array();
        if ($parallel_consumer_conflict) {
            $provider_index = $this->runtime_js_scan_inventory_index_for_exact_script($provider_script, $scripts);
            $consumer_index = $this->runtime_js_scan_inventory_index_for_exact_script($consumer_script, $scripts);
            if ($provider_index < 0 || $consumer_index < 0 || $provider_index >= $consumer_index || '' === $consumer_candidate) {
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $provider_candidate,
                    'jQuery plugin parallel dependency review',
                    '' !== $provider_src ? $provider_src : $provider_candidate,
                    $message,
                    'Runtime Scan proved the jQuery.fn.' . sanitize_text_field($method) . ' provider and direct async consumer, but it could not prove a provider-before-consumer page order that an explicit blocking compatibility exclusion would restore. Keep this as review evidence instead of overriding Parallel Execution or inventing a defer downgrade.',
                    $exclusions,
                    'recommended',
                    '',
                    false,
                    false
                );
                return true;
            }
            $parallel_companion_providers = $this->runtime_js_scan_parallel_jquery_companion_providers(
                $method,
                $consumer_script,
                $scripts
            );
        }

        $provider_proof = (string) ($consumer_script['_jqueryProviderProof'] ?? '');
        $observed_same_owner_proof = 'observed-same-owner' === $provider_proof;
        $observed_same_owner_member_proof = 'observed-same-owner-member' === $provider_proof;
        if ($observed_same_owner_member_proof) {
            $member_receiver = sanitize_text_field((string) ($consumer_script['_jqueryMemberReceiver'] ?? 'member receiver'));
            $reason = 'The browser reports ' . $member_receiver . '.' . sanitize_text_field($method)
                . ' as unavailable in the stack-resolved consumer. Runtime Scan confirmed that consumer source contains the exact ' . $member_receiver . '.' . sanitize_text_field($method)
                . '() call and found exactly one earlier loaded script from the same plugin/theme owner that registers jQuery.fn.' . sanitize_text_field($method)
                . ' from source text. The provider executes as ' . $provider_strategy . ' while the consumer executes as ' . $consumer_strategy
                . ', so the observed execution order can leave the plugin method unavailable when the consumer runs.';
        } elseif ($observed_same_owner_proof) {
            $reason = 'The browser reports the exact minified/local call .' . sanitize_text_field($method)
                . '() as unavailable in the stack-resolved consumer. Runtime Scan confirmed that consumer source contains the reported receiver(...).' . sanitize_text_field($method)
                . '() call and found exactly one earlier loaded script from the same plugin/theme owner that registers jQuery.fn.' . sanitize_text_field($method)
                . ' from source text. The provider executes as ' . $provider_strategy . ' while the consumer executes as ' . $consumer_strategy
                . ', so the observed execution order can leave the method unavailable when the consumer runs.';
        } else {
            $reason = 'Runtime Scan found the exact active plugin/theme script that registers jQuery.fn.' . sanitize_text_field($method)
                . ' and the direct consumer that calls it. The scanned page executes the provider as ' . $provider_strategy
                . ' while the consumer executes as ' . $consumer_strategy
                . ', so the provider can run too late.';
        }
        if ($parallel_consumer_conflict) {
            $reason .= ' Parallel Execution remains authoritative: this is an explicit compatibility exception for the proven provider/consumer chain, not a hidden downgrade to defer. Keep both scripts blocking in their already-proven provider-before-consumer document order.';
        } elseif ('delay' === $provider_strategy && 'defer' === $consumer_strategy) {
            $reason .= ' First keep the proven consumer in the delayed execution class. If the same error persists, promote only the provider through the existing Defer Instead / Do Not Defer or Delay safeguards.';
        } else {
            $reason .= ' Change only the provider to the least-invasive earlier strategy; the consumer is causal evidence and does not need its own safeguard.';
        }

        $this->runtime_js_scan_remove_redundant_persistent_source_suggestions(
            $suggestions,
            array($source, (string) ($consumer['src'] ?? ''))
        );
        $this->runtime_js_scan_remove_resolved_jquery_plugin_generic_fallbacks(
            $suggestions,
            $method,
            $source,
            $message,
            $detail,
            $consumer
        );

        $delay_suggestion = $this->runtime_js_scan_delay_consumer_suggestion($provider_strategy, $consumer_strategy, $consumer_script);
        $this->runtime_js_scan_add_suggestion(
            $suggestions,
            $seen,
            $provider_candidate,
            'jQuery plugin provider script',
            $provider_src,
            $message,
            $reason,
            $exclusions,
            'recommended',
            $preferred_target,
            '' === $provider_fragment && '' !== $provider_id,
            null,
            $delay_suggestion
        );

        if ($parallel_consumer_conflict && !empty($parallel_companion_providers)) {
            $primary_provider_identity = $this->runtime_js_scan_unique_loaded_script_identity($provider_script);
            foreach ($parallel_companion_providers as $companion_provider) {
                $companion_script = isset($companion_provider['script']) && is_array($companion_provider['script']) ? $companion_provider['script'] : array();
                if (empty($companion_script)) {
                    continue;
                }
                $companion_identity = $this->runtime_js_scan_unique_loaded_script_identity($companion_script);
                if ('' === $companion_identity || $companion_identity === $primary_provider_identity) {
                    continue;
                }
                $companion_candidate = $this->runtime_js_scan_dependency_suggestion_for_script($companion_script);
                if ('' === $companion_candidate) {
                    continue;
                }
                $companion_src = (string) ($companion_script['src'] ?? '');
                $companion_fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($companion_src, 5);
                $companion_method = sanitize_text_field((string) ($companion_provider['method'] ?? ''));
                $companion_strategy = sanitize_text_field((string) ($companion_provider['strategy'] ?? ''));
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $companion_candidate,
                    'jQuery plugin parallel companion provider',
                    $companion_src,
                    $message,
                    'The same direct async consumer also calls jQuery.fn.' . $companion_method . ', and Runtime Scan proved exactly one loaded provider for that method before the consumer. Because the consumer is being restored to blocking compatibility order for the reported jQuery-plugin race, keep this companion provider blocking as well instead of leaving it ' . $companion_strategy . ' and creating a second provider/consumer race.',
                    $exclusions,
                    'recommended',
                    'exclusion',
                    '' === $companion_fragment && '' !== sanitize_key((string) ($companion_script['handle'] ?? ''))
                );
            }
        }

        if ($parallel_consumer_conflict) {
            $consumer_src = (string) ($consumer_script['src'] ?? '');
            $consumer_fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($consumer_src, 5);
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $consumer_candidate,
                'jQuery plugin parallel consumer script',
                $consumer_src,
                $message,
                'The direct consumer of jQuery.fn.' . sanitize_text_field($method) . ' is running async under Parallel Execution and the browser proved that its provider was not available at execution time. Parallel Execution still wins globally; this exact consumer joins its proven provider in Do Not Defer or Delay as an explicit compatibility chain so their original blocking document order is restored.',
                $exclusions,
                'recommended',
                'exclusion',
                '' === $consumer_fragment && '' !== sanitize_key((string) ($consumer_script['handle'] ?? ''))
            );
        }

        // Keep the existing per-error cleanup above, and additionally remember
        // this proven provider/consumer relation so a duplicate representation
        // of the same runtime error cannot re-add a generic fallback later in
        // the same diagnostic batch.
        $this->runtime_js_scan_register_resolved_jquery_plugin_context(
            $method,
            $source,
            $message,
            $detail,
            $consumer
        );

        return true;
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

    private function runtime_js_scan_clean_functional_console_message($message)
    {
        $message = trim((string) $message);
        if ('' === $message) {
            return '';
        }

        // Chrome/Edge often prefix plain console messages with the emitting
        // script location, e.g. app.min.js?ver=1.2:1 Message text.
        $message = preg_replace('/^\s*\S+\.m?js(?:\?\S*)?(?::\d+){1,2}\s+/i', '', $message);
        $message = is_string($message) ? trim($message) : '';
        return sanitize_text_field(substr($message, 0, 500));
    }

    private function runtime_js_scan_is_functional_failure_console_message($message)
    {
        $clean_message = $this->runtime_js_scan_clean_functional_console_message($message);
        if ($this->runtime_js_scan_is_explicit_runtime_error($clean_message)) {
            return false;
        }

        $message = strtolower($clean_message);
        if (strlen($message) < 6) {
            return false;
        }

        // Some plugins intentionally report a fatal functional state through
        // console.log() instead of throwing. Keep this semantic and generic:
        // capture failure language, not product-specific phrases.
        return (bool) preg_match(
            '/\b(?:not|never)\s+(?:loaded|initialized|initialised|available|ready|defined|found|created|rendered)(?:\s+properly)?\b|\b(?:failed|unable)\s+to\s+(?:load|initialize|initialise|start|create|render|resolve|find)\b|\bcould\s+not\s+(?:load|initialize|initialise|start|create|render|resolve|find)\b|\b(?:missing|unavailable)\s+(?:dependency|dependencies|library|libraries|script|scripts|file|files|module|modules|provider|global)\b/i',
            $message
        );
    }

    private function runtime_js_scan_guarded_missing_symbols_near_console_message($content, $message)
    {
        $content = (string) $content;
        $message = $this->runtime_js_scan_clean_functional_console_message($message);
        if ('' === $content || '' === $message) {
            return array();
        }

        $pos = strpos($content, $message);
        if (false === $pos) {
            $pos = stripos($content, $message);
        }
        if (false === $pos) {
            return array();
        }

        $start = max(0, (int) $pos - 1600);
        $prefix = substr($content, $start, (int) $pos - $start);
        if (!is_string($prefix) || '' === $prefix) {
            return array();
        }

        $patterns = array(
            '/if\s*\(\s*typeof\s+(?:(?:window|globalThis)\s*\.\s*)?([A-Za-z_$][A-Za-z0-9_$]*)\s*(?:===|==)\s*[\'\"]undefined[\'\"]\s*\)/i',
            '/if\s*\(\s*[\'\"]undefined[\'\"]\s*(?:===|==)\s*typeof\s+(?:(?:window|globalThis)\s*\.\s*)?([A-Za-z_$][A-Za-z0-9_$]*)\s*\)/i',
            '/if\s*\(\s*!\s*(?:window|globalThis)\s*\.\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\)/i',
            // Minifiers commonly collapse the same missing-global guard into
            // a ternary expression immediately before the failure branch.
            '/typeof\s+(?:(?:window|globalThis)\s*\.\s*)?([A-Za-z_$][A-Za-z0-9_$]*)\s*(?:===|==)\s*[\'\"]undefined[\'\"]\s*\?/i',
            '/[\'\"]undefined[\'\"]\s*(?:===|==)\s*typeof\s+(?:(?:window|globalThis)\s*\.\s*)?([A-Za-z_$][A-Za-z0-9_$]*)\s*\?/i',
            '/!\s*(?:window|globalThis)\s*\.\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\?/i',
        );

        $nearest = array();
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $prefix, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches as $match) {
                $symbol = sanitize_text_field((string) ($match[1][0] ?? ''));
                $offset = isset($match[0][1]) ? (int) $match[0][1] : -1;
                if ('' === $symbol || $offset < 0 || !$this->runtime_js_scan_is_actionable_missing_symbol($symbol)) {
                    continue;
                }
                $nearest[$offset] = $symbol;
            }
        }
        if (empty($nearest)) {
            return array();
        }
        krsort($nearest, SORT_NUMERIC);
        return array(sanitize_text_field((string) reset($nearest)));
    }

    private function runtime_js_scan_declared_dependency_closure_scripts(array $consumer, array $scripts, $max_depth = 3)
    {
        $max_depth = max(1, min(4, (int) $max_depth));
        $queue = array();
        foreach ((array) ($consumer['deps'] ?? array()) as $dependency) {
            $dependency = sanitize_key((string) $dependency);
            if ('' !== $dependency) {
                $queue[] = array($dependency, 1);
            }
        }
        $out = array();
        $seen = array();
        while (!empty($queue) && count($out) < 32) {
            $item = array_shift($queue);
            $handle = sanitize_key((string) ($item[0] ?? ''));
            $depth = (int) ($item[1] ?? 1);
            if ('' === $handle || isset($seen[$handle]) || $depth > $max_depth) {
                continue;
            }
            $seen[$handle] = true;
            $script = $this->runtime_js_scan_find_inventory_script_by_handle($handle, $scripts);
            if (empty($script)) {
                continue;
            }
            $out[] = $script;
            if ($depth >= $max_depth) {
                continue;
            }
            foreach ((array) ($script['deps'] ?? array()) as $dependency) {
                $dependency = sanitize_key((string) $dependency);
                if ('' !== $dependency && !isset($seen[$dependency])) {
                    $queue[] = array($dependency, $depth + 1);
                }
            }
        }
        return $out;
    }

    private function runtime_js_scan_guard_symbol_tail_token($symbol)
    {
        $symbol = preg_replace('/[^A-Za-z0-9_$]/', '', trim((string) $symbol));
        if ('' === $symbol) {
            return '';
        }
        if (preg_match('/([A-Za-z]{4,})$/', $symbol, $match)) {
            return strtolower((string) ($match[1] ?? ''));
        }
        return strtolower($symbol);
    }

    private function runtime_js_scan_declared_dependency_identity_matches_guard_symbol(array $script, $symbol)
    {
        $full = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', (string) $symbol));
        $tail = $this->runtime_js_scan_guard_symbol_tail_token($symbol);
        if ('' === $full) {
            return false;
        }

        $parts = array();
        foreach (array('handle', 'id') as $field) {
            foreach (preg_split('/[^A-Za-z0-9]+/', (string) ($script[$field] ?? '')) as $part) {
                if ('' !== $part) {
                    $parts[] = $part;
                }
            }
        }
        $src = $this->runtime_js_scan_clean_console_candidate((string) ($script['src'] ?? ''));
        if ('' !== $src) {
            $parts[] = basename((string) wp_parse_url($src, PHP_URL_PATH));
        }

        foreach ($parts as $part) {
            $token = $this->runtime_js_scan_dependency_identity_token($part);
            if ('' === $token) {
                continue;
            }
            if ($token === $full || (strlen($tail) >= 4 && $token === $tail)) {
                return true;
            }
        }
        return false;
    }

    private function runtime_js_scan_find_functional_guard_provider($symbol, array $scripts, array $consumer)
    {
        $provider = $this->runtime_js_scan_find_unique_window_symbol_provider($symbol, $scripts, array($consumer));
        if (!empty($provider)) {
            return $provider;
        }

        // External libraries cannot always be read from disk. If the exact
        // failing consumer has a WordPress dependency graph, scope fallback
        // identity matching strictly to that graph and require one unique match.
        $matches = array();
        foreach ($this->runtime_js_scan_declared_dependency_closure_scripts($consumer, $scripts, 3) as $candidate) {
            if (!$this->runtime_js_scan_declared_dependency_identity_matches_guard_symbol((array) $candidate, $symbol)) {
                continue;
            }
            $matches[] = $candidate;
            if (count($matches) > 1) {
                return array();
            }
        }
        if (1 === count($matches)) {
            return (array) $matches[0];
        }

        // A runtime provider can be loaded from a CDN or another unreadable URL,
        // so source-text inspection is not always available and WordPress may not
        // declare the dependency edge. In that case, use only the browser's actual
        // loaded-script inventory and require one unique identity match for the
        // guarded symbol (for example a symbol tail matching one unique core.js).
        // Multiple matches remain ambiguous and are never auto-fixed.
        $loaded_matches = array();
        $loaded_seen = array();
        foreach ($scripts as $candidate) {
            if (!is_array($candidate) || $this->runtime_js_scan_same_inventory_script((array) $candidate, $consumer)) {
                continue;
            }
            $candidate_src = (string) ($candidate['src'] ?? '');
            if ($this->runtime_js_scan_is_ultracache_runtime_helper_source($candidate_src)) {
                continue;
            }
            if (!$this->runtime_js_scan_declared_dependency_identity_matches_guard_symbol((array) $candidate, $symbol)) {
                continue;
            }
            $identity = $this->runtime_js_scan_dependency_suggestion_for_script((array) $candidate);
            if ('' === $identity) {
                $identity = $this->runtime_js_scan_unique_loaded_script_identity((array) $candidate);
            }
            $identity = strtolower(trim((string) $identity));
            if ('' === $identity || isset($loaded_seen[$identity])) {
                continue;
            }
            $loaded_seen[$identity] = true;
            $loaded_matches[] = $candidate;
            if (count($loaded_matches) >= 8) {
                break;
            }
        }

        if (1 === count($loaded_matches)) {
            return (array) $loaded_matches[0];
        }

        // A short guard tail such as "core" can legitimately match several
        // loaded scripts. When that happens, use the exact failing consumer's
        // WordPress plugin/theme owner as an additional deterministic boundary.
        // CDN providers commonly retain the registering plugin's script ID/handle
        // even though their URL has a different host. Require exactly one loaded
        // identity in the same owner namespace; otherwise keep the case ambiguous.
        if (count($loaded_matches) > 1) {
            $owner = $this->runtime_js_scan_owner_group_from_source((string) ($consumer['src'] ?? ''));
            $owner_slug = sanitize_key((string) ($owner['slug'] ?? ''));
            if ('' !== $owner_slug) {
                $owner_matches = array();
                $owner_seen = array();
                $owner_needle = trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', $owner_slug)), '-');
                foreach ($loaded_matches as $candidate) {
                    $identity_text = strtolower((string) ($candidate['handle'] ?? '') . ' ' . (string) ($candidate['id'] ?? ''));
                    $identity_namespace = trim((string) preg_replace('/[^a-z0-9]+/', '-', $identity_text), '-');
                    if ('' === $identity_namespace || '' === $owner_needle || false === strpos('-' . $identity_namespace . '-', '-' . $owner_needle . '-')) {
                        continue;
                    }
                    $candidate_key = $this->runtime_js_scan_unique_loaded_script_identity((array) $candidate);
                    if ('' === $candidate_key || isset($owner_seen[$candidate_key])) {
                        continue;
                    }
                    $owner_seen[$candidate_key] = true;
                    $owner_matches[] = $candidate;
                    if (count($owner_matches) > 1) {
                        break;
                    }
                }
                if (1 === count($owner_matches)) {
                    return (array) $owner_matches[0];
                }
            }
        }

        return array();
    }

    private function runtime_js_scan_add_functional_failure_console_suggestion(&$suggestions, &$seen, $source, $message, $detail, array $scripts, array $exclusions)
    {
        if (!$this->runtime_js_scan_is_functional_failure_console_message($message)) {
            return false;
        }

        $consumers = $this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts);

        $clean_message = $this->runtime_js_scan_clean_functional_console_message($message);

        // A physical browser script may have more than one runtime inventory
        // representation. Resolve each usable representation independently and
        // continue only when all usable evidence converges on one guarded symbol
        // and one provider. Genuine ambiguity stays non-automatic.
        $resolved = array();
        foreach ($consumers as $consumer_candidate) {
            if (!is_array($consumer_candidate)) {
                continue;
            }
            $candidate_content = $this->runtime_js_scan_script_content($consumer_candidate);
            if ('' === $candidate_content) {
                continue;
            }
            $candidate_suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($consumer_candidate);
            if ('' === $candidate_suggestion) {
                continue;
            }
            $candidate_symbols = $this->runtime_js_scan_guarded_missing_symbols_near_console_message($candidate_content, $clean_message);
            if (1 !== count($candidate_symbols)) {
                continue;
            }
            $candidate_symbol = sanitize_text_field((string) $candidate_symbols[0]);
            $candidate_provider = $this->runtime_js_scan_find_functional_guard_provider($candidate_symbol, $scripts, $consumer_candidate);
            if (empty($candidate_provider)) {
                continue;
            }
            $provider_identity = $this->runtime_js_scan_dependency_suggestion_for_script($candidate_provider);
            if ('' === $provider_identity) {
                $provider_identity = $this->runtime_js_scan_unique_loaded_script_identity($candidate_provider);
            }
            if ('' === $provider_identity) {
                continue;
            }
            $key = strtolower($candidate_symbol . '|' . $provider_identity);
            if (!isset($resolved[$key])) {
                $resolved[$key] = array(
                    'symbol' => $candidate_symbol,
                    'provider' => $candidate_provider,
                    'consumer' => $consumer_candidate,
                    'consumer_suggestion' => $candidate_suggestion,
                );
            }
        }

        if (1 === count($resolved)) {
            $relationship = (array) reset($resolved);
            $consumer = isset($relationship['consumer']) && is_array($relationship['consumer']) ? $relationship['consumer'] : array();
            $provider = isset($relationship['provider']) && is_array($relationship['provider']) ? $relationship['provider'] : array();
            $symbol = sanitize_text_field((string) ($relationship['symbol'] ?? ''));
            $consumer_suggestion = sanitize_text_field((string) ($relationship['consumer_suggestion'] ?? ''));
            if (!empty($consumer) && !empty($provider) && '' !== $symbol && '' !== $consumer_suggestion) {
                $consumer_name = sanitize_text_field((string) ($consumer['handle'] ?? $consumer['id'] ?? $consumer_suggestion));
                $provider_strategy = $this->runtime_js_scan_script_effective_strategy($provider);
                $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($consumer);
                $preferred_target = $this->runtime_js_scan_declared_dependency_preferred_target($provider_strategy, $consumer_strategy, false);
                $observed_order_failure = false;
                if ('' === $preferred_target) {
                    $preferred_target = $this->runtime_js_scan_observed_missing_receiver_preferred_target($provider_strategy);
                    $observed_order_failure = '' !== $preferred_target;
                }
                $provider_suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($provider);
                if ('' !== $preferred_target && '' !== $provider_suggestion) {
                    $provider_name = sanitize_text_field((string) ($provider['handle'] ?? $provider['id'] ?? $provider_suggestion));
                    $delay_suggestion = $this->runtime_js_scan_delay_consumer_suggestion($provider_strategy, $consumer_strategy, $consumer);
                    $this->runtime_js_scan_add_suggestion(
                        $suggestions,
                        $seen,
                        $provider_suggestion,
                        'functional console guard provider ' . $symbol,
                        (string) ($provider['src'] ?? ''),
                        trim($clean_message . "\n" . (string) $detail),
                        'The exact loaded consumer "' . $consumer_name . '" reported the functional failure "' . $clean_message . '" without throwing an exception. Runtime Scan found that this log is emitted immediately after a source guard proving "' . $symbol . '" is unavailable. Exactly one loaded provider is proven for that guarded symbol within the loaded scripts or the consumer\'s declared WordPress dependency chain: "' . $provider_name . '". ' . ($observed_order_failure ? 'The observed functional failure itself proves that the provider was unavailable when the consumer ran. ' : '') . 'When the provider is delayed and the consumer is deferred, first keep the proven consumer in the delayed execution class; if the failure persists, use the existing provider-promotion safeguards and rescan.',
                        $exclusions,
                        'recommended',
                        $preferred_target,
                        true,
                        null,
                        $delay_suggestion
                    );
                    return true;
                }
            }
        }

        // A confirmed functional console failure must never disappear merely
        // because the rich script inventory does not contain one usable consumer
        // record. Browser stacks are causal evidence on their own. When the stack
        // gives one exact non-UltraCache script URL, use that exact source as a
        // bounded synthetic consumer for source inspection and review evidence.
        // This can also recover an automatic provider fix when the consumer was
        // omitted from the bounded inventory but the unique provider is present.
        $review_consumer = array();
        $review_note = '';
        if (1 === count($consumers)) {
            $review_consumer = (array) $consumers[0];
        } elseif (!empty($consumers)) {
            $source_groups = array();
            foreach ($consumers as $consumer_candidate) {
                if (!is_array($consumer_candidate)) {
                    continue;
                }
                $candidate_src = strtolower($this->runtime_js_scan_clean_console_candidate((string) ($consumer_candidate['src'] ?? '')));
                if ('' === $candidate_src) {
                    continue;
                }
                if (!isset($source_groups[$candidate_src])) {
                    $source_groups[$candidate_src] = array();
                }
                $source_groups[$candidate_src][] = $consumer_candidate;
            }
            if (1 === count($source_groups)) {
                $same_source_candidates = (array) reset($source_groups);
                foreach ($same_source_candidates as $consumer_candidate) {
                    if ('' !== $this->runtime_js_scan_dependency_suggestion_for_script((array) $consumer_candidate)) {
                        $review_consumer = (array) $consumer_candidate;
                        if ('' !== $this->runtime_js_scan_script_content($review_consumer)) {
                            break;
                        }
                    }
                }
                if (!empty($review_consumer)) {
                    $review_note = ' Multiple runtime inventory representations resolve to this same exact browser source, so UltraCache coalesced them for review instead of dropping the confirmed failure.';
                }
            }
        }

        if (empty($review_consumer)) {
            $exact_source = $this->runtime_js_scan_sanitize_source((string) $source);
            if ('' !== $exact_source && !$this->runtime_js_scan_is_ultracache_runtime_helper_source($exact_source)) {
                $synthetic_suggestion = $this->runtime_js_scan_dependency_suggestion_for_script(array('src' => $exact_source));
                if ('' !== $synthetic_suggestion) {
                    $review_consumer = array(
                        'id'       => '',
                        'handle'   => '',
                        'src'      => $exact_source,
                        'type'     => '',
                        'defer'    => false,
                        'async'    => false,
                        'strategy' => '',
                        'delayed'  => false,
                        'deps'     => array(),
                        'text'     => '',
                    );
                    $review_note = ' The browser stack proves this exact consumer source, but no unique rich inventory record survived normalization, so UltraCache used the exact source directly instead of discarding the failure.';
                }
            }
        }

        if (empty($review_consumer)) {
            return false;
        }

        $content = $this->runtime_js_scan_script_content($review_consumer);
        $consumer_suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($review_consumer);
        if ('' === $consumer_suggestion) {
            return false;
        }
        $consumer_name = sanitize_text_field((string) ($review_consumer['handle'] ?? $review_consumer['id'] ?? $consumer_suggestion));
        if ('' === $consumer_name) {
            $consumer_name = $consumer_suggestion;
        }
        $symbols = $this->runtime_js_scan_guarded_missing_symbols_near_console_message($content, $clean_message);

        // If the exact browser source was missing from the inventory but remains
        // readable locally, give the existing provider resolver one deterministic
        // chance using that source. The observed failure proves ordering; provider
        // strategy is used only to choose the least-invasive safeguard.
        if (1 === count($symbols)) {
            $symbol = sanitize_text_field((string) $symbols[0]);
            $provider = $this->runtime_js_scan_find_functional_guard_provider($symbol, $scripts, $review_consumer);
            $provider_suggestion = !empty($provider) ? $this->runtime_js_scan_dependency_suggestion_for_script($provider) : '';
            $provider_strategy = !empty($provider) ? $this->runtime_js_scan_script_effective_strategy($provider) : '';
            $preferred_target = '' !== $provider_strategy ? $this->runtime_js_scan_observed_missing_receiver_preferred_target($provider_strategy) : '';
            if ('' !== $provider_suggestion && '' !== $preferred_target) {
                $provider_name = sanitize_text_field((string) ($provider['handle'] ?? $provider['id'] ?? $provider_suggestion));
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $provider_suggestion,
                    'functional console guard provider ' . $symbol,
                    (string) ($provider['src'] ?? ''),
                    trim($clean_message . "\n" . (string) $detail),
                    'The browser stack proves the exact consumer "' . $consumer_name . '" and source inspection proves that its functional failure is guarded by missing runtime symbol "' . $symbol . '". Exactly one loaded provider is proven for that symbol: "' . $provider_name . '". The observed failure proves that provider was unavailable when the consumer ran, so move only that provider to the least-invasive earlier strategy and rescan.',
                    $exclusions,
                    'recommended',
                    $preferred_target,
                    true
                );
                return true;
            }
        }

        $guard_text = 1 === count($symbols)
            ? ' Runtime Scan also confirmed that the message is emitted behind a missing-runtime guard for "' . sanitize_text_field((string) $symbols[0]) . '", but no unique moveable loaded provider was proven.'
            : ('' === $content ? ' The exact consumer source could not be read for guard inspection.' : ' No single actionable missing-runtime guard could be proven near this console message.');
        $this->runtime_js_scan_add_suggestion(
            $suggestions,
            $seen,
            $consumer_suggestion,
            'functional console failure',
            (string) ($review_consumer['src'] ?? $source),
            trim($clean_message . "\n" . (string) $detail),
            'The exact browser consumer "' . $consumer_name . '" reported the functional failure "' . $clean_message . '" through console output instead of throwing a JavaScript exception.' . $review_note . $guard_text . ' Keep this exact source as review evidence rather than ignoring the failure or guessing an unrelated exclusion.',
            $exclusions,
            'review',
            '',
            false,
            false
        );
        return true;
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

    private function runtime_js_scan_extract_jquery_plugin_calls_from_error($message, $detail = '')
    {
        $text = (string) $message . "\n" . (string) $detail;
        $calls = array();
        if (!preg_match_all('/(?:TypeError:\s*)?([A-Za-z_$][A-Za-z0-9_$]*)\s*\([^\n]{0,1600}?\)\s*\.\s*([A-Za-z_$][A-Za-z0-9_$-]*)\s+is\s+not\s+a\s+function/i', $text, $matches, PREG_SET_ORDER)) {
            return array();
        }

        foreach ($matches as $match) {
            $receiver = trim((string) ($match[1] ?? ''));
            $method = trim((string) ($match[2] ?? ''));
            if ('' === $receiver || '' === $method
                || !preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $receiver)
                || !preg_match('/^[A-Za-z_$][A-Za-z0-9_$-]{1,80}$/', $method)) {
                continue;
            }
            $key = strtolower($receiver . '|' . $method);
            $calls[$key] = array(
                'receiver' => sanitize_text_field($receiver),
                'method'   => sanitize_text_field($method),
            );
        }

        return array_values($calls);
    }

    /**
     * Extract member-expression jQuery plugin failures such as:
     *   this.instance.flickity is not a function
     *   carousel.$element.slick is not a function
     *
     * This does not prove the member itself is a jQuery object. It only captures
     * the exact browser-reported receiver expression and method so the narrower
     * same-owner/provider/order resolver below can decide whether the failure is
     * actionable.
     */
    private function runtime_js_scan_extract_jquery_plugin_member_calls_from_error($message, $detail = '')
    {
        $text = (string) $message . "\n" . (string) $detail;
        $calls = array();

        $identifier = '[A-Za-z_$][A-Za-z0-9_$]*';
        $receiver_pattern = '(?:this|' . $identifier . ')(?:\s*\.\s*' . $identifier . '){1,6}';
        $pattern = '/(?:TypeError:\s*)?(' . $receiver_pattern . ')\s*\.\s*(' . $identifier . ')\s+is\s+not\s+a\s+function/i';

        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return array();
        }

        foreach ($matches as $match) {
            $receiver_expression = preg_replace('/\s+/', '', trim((string) ($match[1] ?? '')));
            $method = trim((string) ($match[2] ?? ''));
            if ('' === $receiver_expression || '' === $method
                || !preg_match('/^(?:this|[A-Za-z_$][A-Za-z0-9_$]*)(?:\.[A-Za-z_$][A-Za-z0-9_$]*){1,6}$/', $receiver_expression)
                || !preg_match('/^[A-Za-z_$][A-Za-z0-9_$-]{1,80}$/', $method)) {
                continue;
            }

            $key = strtolower($receiver_expression . '|' . $method);
            $calls[$key] = array(
                'receiver'     => sanitize_text_field($receiver_expression),
                'method'       => sanitize_text_field($method),
                'receiverType' => 'member',
            );
        }

        return array_values($calls);
    }

    private function runtime_js_scan_extract_missing_jquery_methods_from_error($message, $detail = '')
    {
        $methods = array();
        foreach ($this->runtime_js_scan_extract_jquery_plugin_calls_from_error($message, $detail) as $call) {
            $receiver = (string) ($call['receiver'] ?? '');
            if (!in_array(strtolower($receiver), array('$', 'jquery'), true)) {
                continue;
            }
            $method = (string) ($call['method'] ?? '');
            if ('' !== $method) {
                $methods[strtolower($method)] = $method;
            }
        }
        return array_values($methods);
    }

    /**
     * jQuery plugin errors are often rethrown through wrapper scripts (consent,
     * instrumentation, jQuery hooks). Keep the browser-reported primary source,
     * but also inspect exact external stack frames for this error class. A frame
     * becomes a consumer only if later source-level method proof succeeds.
     */
    private function runtime_js_scan_jquery_error_consumer_inventory_scripts($source, $message, $detail, array $scripts)
    {
        $matches = array();
        $seen = array();
        $push = function ($script) use (&$matches, &$seen) {
            if (!is_array($script)) {
                return;
            }
            $identity = $this->runtime_js_scan_unique_loaded_script_identity($script);
            if ('' === $identity || isset($seen[$identity])) {
                return;
            }
            $seen[$identity] = true;
            $matches[] = $script;
        };

        foreach ($this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts) as $script) {
            $push($script);
        }

        $candidates = $this->runtime_js_scan_source_candidates_from_error($source, $message, $detail);
        foreach ($this->runtime_js_scan_console_sources_from_text((string) $message . "\n" . (string) $detail) as $candidate) {
            $candidates[] = $candidate;
        }
        foreach ($candidates as $candidate) {
            foreach ($this->runtime_js_scan_exact_error_source_inventory_scripts($candidate, $scripts) as $script) {
                $push($script);
            }
            if (count($matches) >= 10) {
                break;
            }
        }

        return array_slice($matches, 0, 10);
    }

    private function runtime_js_scan_find_proven_jquery_alias_consumer($receiver, $method, $source, $message, $detail, array $scripts)
    {
        $receiver = trim((string) $receiver);
        $method = trim((string) $method);
        if ('' === $receiver || '' === $method || in_array(strtolower($receiver), array('$', 'jquery'), true)) {
            return array();
        }

        $matches = array();
        $seen = array();
        foreach ($this->runtime_js_scan_jquery_error_consumer_inventory_scripts($source, $message, $detail, $scripts) as $consumer) {
            if (!is_array($consumer)) {
                continue;
            }
            $content = $this->runtime_js_scan_script_content($consumer);
            if (!$this->runtime_js_scan_jquery_alias_consumer_is_proven($content, $receiver, $method)) {
                continue;
            }
            $identity = $this->runtime_js_scan_unique_loaded_script_identity($consumer);
            if ('' === $identity) {
                $identity = strtolower((string) ($consumer['src'] ?? '') . '|' . (string) ($consumer['id'] ?? '') . '|' . (string) ($consumer['handle'] ?? ''));
            }
            if ('' === $identity || isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $matches[] = $consumer;
            if (count($matches) > 1) {
                break;
            }
        }

        return 1 === count($matches) ? $matches[0] : array();
    }

    /**
     * Additive fallback for minified/local aliases whose exact alias-to-jQuery
     * binding cannot be reconstructed from the consumer source. This does not
     * replace the stricter alias proof above. It is accepted only when the
     * browser stack resolves one exact consumer, that source contains the exact
     * receiver(...).method() call, exactly one earlier loaded same-owner script
     * registers jQuery.fn.method from source text, and the scanned execution
     * strategies prove that provider can run too late.
     */
    private function runtime_js_scan_find_observed_same_owner_jquery_plugin_consumer($receiver, $method, $source, $message, $detail, array $scripts, array $page_providers)
    {
        $receiver = trim((string) $receiver);
        $method = trim((string) $method);
        if ('' === $receiver || '' === $method || in_array(strtolower($receiver), array('$', 'jquery'), true) || 1 !== count($page_providers)) {
            return array();
        }

        $provider = isset($page_providers[0]) && is_array($page_providers[0]) ? $page_providers[0] : array();
        $provider_script = isset($provider['script']) && is_array($provider['script']) ? $provider['script'] : array();
        if (empty($provider_script)) {
            return array();
        }

        // This fallback deliberately requires source-level provider proof. A
        // filename/handle resemblance is not enough when the consumer alias is
        // minified and its jQuery binding could not be reconstructed.
        $provider_content = $this->runtime_js_scan_script_content($provider_script);
        if ('' === $provider_content || !$this->runtime_js_scan_jquery_file_defines_method($provider_content, $method, '')) {
            return array();
        }

        $provider_src = (string) ($provider_script['src'] ?? '');
        $provider_owner = $this->runtime_js_scan_owner_group_from_source($provider_src);
        if (empty($provider_owner['kind']) || empty($provider_owner['slug'])) {
            return array();
        }

        $alias_regex = preg_quote($receiver, '/');
        $method_regex = preg_quote($method, '/');
        $call_pattern = '/(?:^|[^A-Za-z0-9_$])' . $alias_regex
            . '\s*\([^;\n]{0,1200}?\)\s*(?:\.\s*' . $method_regex
            . '|\[\s*["\']' . $method_regex . '["\']\s*\])\s*\(/i';

        $matches = array();
        $seen = array();
        foreach ($this->runtime_js_scan_jquery_error_consumer_inventory_scripts($source, $message, $detail, $scripts) as $consumer) {
            if (!is_array($consumer)) {
                continue;
            }
            $consumer_content = $this->runtime_js_scan_script_content($consumer);
            if ('' === $consumer_content || !preg_match($call_pattern, $consumer_content)) {
                continue;
            }

            $consumer_src = (string) ($consumer['src'] ?? '');
            $consumer_owner = $this->runtime_js_scan_owner_group_from_source($consumer_src);
            if (empty($consumer_owner['kind']) || empty($consumer_owner['slug'])
                || (string) $consumer_owner['kind'] !== (string) $provider_owner['kind']
                || (string) $consumer_owner['slug'] !== (string) $provider_owner['slug']) {
                continue;
            }

            $provider_index = $this->runtime_js_scan_inventory_index_for_exact_script($provider_script, $scripts);
            $consumer_index = $this->runtime_js_scan_inventory_index_for_exact_script($consumer, $scripts);
            if ($provider_index < 0 || $consumer_index < 0 || $provider_index >= $consumer_index) {
                continue;
            }

            $provider_strategy = $this->runtime_js_scan_script_effective_strategy($provider_script);
            $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($consumer);
            if ('' === $this->runtime_js_scan_declared_dependency_preferred_target($provider_strategy, $consumer_strategy, false)) {
                continue;
            }

            $identity = $this->runtime_js_scan_unique_loaded_script_identity($consumer);
            if ('' === $identity || isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $consumer['_jqueryProviderProof'] = 'observed-same-owner';
            $matches[] = $consumer;
            if (count($matches) > 1) {
                return array();
            }
        }

        return 1 === count($matches) ? $matches[0] : array();
    }

    /**
     * Additive fallback for browser-reported member receivers such as
     * this.instance.flickity(). The member is not assumed to be jQuery. The
     * resolver becomes actionable only when the exact stack-resolved consumer
     * contains that exact member call, exactly one earlier loaded same-owner
     * script registers jQuery.fn.method from source text, and the observed
     * execution strategies prove the provider can run too late.
     */
    private function runtime_js_scan_find_observed_same_owner_jquery_member_consumer($receiver_expression, $method, $source, $message, $detail, array $scripts, array $page_providers)
    {
        $receiver_expression = preg_replace('/\s+/', '', trim((string) $receiver_expression));
        $method = trim((string) $method);
        if ('' === $receiver_expression || '' === $method
            || !preg_match('/^(?:this|[A-Za-z_$][A-Za-z0-9_$]*)(?:\.[A-Za-z_$][A-Za-z0-9_$]*){1,6}$/', $receiver_expression)
            || 1 !== count($page_providers)) {
            return array();
        }

        $provider = isset($page_providers[0]) && is_array($page_providers[0]) ? $page_providers[0] : array();
        $provider_script = isset($provider['script']) && is_array($provider['script']) ? $provider['script'] : array();
        if (empty($provider_script)) {
            return array();
        }

        $provider_content = $this->runtime_js_scan_script_content($provider_script);
        if ('' === $provider_content || !$this->runtime_js_scan_jquery_file_defines_method($provider_content, $method, '')) {
            return array();
        }

        $provider_src = (string) ($provider_script['src'] ?? '');
        $provider_owner = $this->runtime_js_scan_owner_group_from_source($provider_src);
        if (empty($provider_owner['kind']) || empty($provider_owner['slug'])) {
            return array();
        }

        $receiver_parts = explode('.', $receiver_expression);
        $receiver_regex_parts = array();
        foreach ($receiver_parts as $part) {
            $receiver_regex_parts[] = preg_quote($part, '/');
        }
        $receiver_regex = implode('\s*\.\s*', $receiver_regex_parts);
        $method_regex = preg_quote($method, '/');
        $call_pattern = '/(?:^|[^A-Za-z0-9_$])' . $receiver_regex
            . '\s*(?:\.\s*' . $method_regex
            . '|\[\s*["\']' . $method_regex . '["\']\s*\])\s*\(/i';

        $matches = array();
        $seen = array();
        foreach ($this->runtime_js_scan_jquery_error_consumer_inventory_scripts($source, $message, $detail, $scripts) as $consumer) {
            if (!is_array($consumer)) {
                continue;
            }

            $consumer_content = $this->runtime_js_scan_script_content($consumer);
            if ('' === $consumer_content || !preg_match($call_pattern, $consumer_content)) {
                continue;
            }

            $consumer_src = (string) ($consumer['src'] ?? '');
            $consumer_owner = $this->runtime_js_scan_owner_group_from_source($consumer_src);
            if (empty($consumer_owner['kind']) || empty($consumer_owner['slug'])
                || (string) $consumer_owner['kind'] !== (string) $provider_owner['kind']
                || (string) $consumer_owner['slug'] !== (string) $provider_owner['slug']) {
                continue;
            }

            $provider_index = $this->runtime_js_scan_inventory_index_for_exact_script($provider_script, $scripts);
            $consumer_index = $this->runtime_js_scan_inventory_index_for_exact_script($consumer, $scripts);
            if ($provider_index < 0 || $consumer_index < 0 || $provider_index >= $consumer_index) {
                continue;
            }

            $provider_strategy = $this->runtime_js_scan_script_effective_strategy($provider_script);
            $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($consumer);
            if ('' === $this->runtime_js_scan_declared_dependency_preferred_target($provider_strategy, $consumer_strategy, false)) {
                continue;
            }

            $identity = $this->runtime_js_scan_unique_loaded_script_identity($consumer);
            if ('' === $identity) {
                $identity = strtolower((string) ($consumer['src'] ?? '') . '|' . (string) ($consumer['id'] ?? '') . '|' . (string) ($consumer['handle'] ?? ''));
            }
            if ('' === $identity || isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $consumer['_jqueryProviderProof'] = 'observed-same-owner-member';
            $consumer['_jqueryMemberReceiver'] = sanitize_text_field($receiver_expression);
            $matches[] = $consumer;
            if (count($matches) > 1) {
                return array();
            }
        }

        return 1 === count($matches) ? $matches[0] : array();
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
            if (!$this->runtime_js_scan_is_explicit_missing_global($symbol)) {
                return;
            }
            $symbols[strtolower($symbol)] = sanitize_text_field(substr($symbol, 0, 120));
        };

        // Only a real ReferenceError proves that a global/provider is missing.
        // A TypeError such as X.foo is not a function proves that X already
        // exists and has the wrong runtime state/type for this consumer.
        if (preg_match_all('/(?:ReferenceError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]*)\s+is\s+not\s+defined/i', $text, $matches)) {
            foreach ((array) $matches[1] as $symbol) {
                $push($symbol);
            }
        }

        return array_values($symbols);
    }

    private function runtime_js_scan_extract_wrong_type_member_receivers_from_error($message, $detail = '')
    {
        $text = (string) $message . "\n" . (string) $detail;
        $receivers = array();
        $push = function ($receiver, $member = '') use (&$receivers) {
            $receiver = preg_replace('/[^A-Za-z0-9_$.-]/', '', trim((string) $receiver));
            $member = preg_replace('/[^A-Za-z0-9_$-]/', '', trim((string) $member));
            if ('' === $receiver || !$this->runtime_js_scan_is_explicit_missing_global($receiver)) {
                return;
            }
            $key = strtolower($receiver . '|' . $member);
            $receivers[$key] = array(
                'receiver' => sanitize_text_field(substr($receiver, 0, 120)),
                'member'   => sanitize_text_field(substr($member, 0, 80)),
            );
        };

        if (preg_match_all('/(?:TypeError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]*)\s*\.\s*([A-Za-z_$][A-Za-z0-9_$-]*)\s+is\s+not\s+a\s+function/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $push((string) ($match[1] ?? ''), (string) ($match[2] ?? ''));
            }
        }
        if (preg_match_all('/TypeError:\s*([A-Za-z_$][A-Za-z0-9_$-]*)\s+is\s+not\s+a\s+function/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $push((string) ($match[1] ?? ''), '');
            }
        }

        return array_values($receivers);
    }

    private function runtime_js_scan_extract_undefined_property_reads_from_error($message, $detail = '')
    {
        $text = (string) $message . "\n" . (string) $detail;
        $reads = array();

        if (preg_match_all('/Cannot\s+read\s+propert(?:y|ies)\s+of\s+(undefined|null)\s*\(reading\s+[\'"]([^\'"]+)[\'"]\)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $state = strtolower(trim((string) ($match[1] ?? '')));
                $property = preg_replace('/[^A-Za-z0-9_$-]/', '', trim((string) ($match[2] ?? '')));
                if (!in_array($state, array('undefined', 'null'), true) || '' === $property) {
                    continue;
                }
                $key = $state . '|' . strtolower($property);
                $reads[$key] = array(
                    'state'    => $state,
                    'property' => sanitize_text_field(substr($property, 0, 120)),
                );
            }
        }

        return array_values($reads);
    }

    private function runtime_js_scan_rhs_reuses_bare_symbol($rhs, $symbol)
    {
        $rhs = (string) $rhs;
        $symbol = trim((string) $symbol);
        if ('' === $rhs || '' === $symbol) {
            return false;
        }

        $quoted = preg_quote($symbol, '/');
        return (bool) preg_match('/(?<![A-Za-z0-9_$.])' . $quoted . '(?![A-Za-z0-9_$])/i', $rhs);
    }

    private function runtime_js_scan_file_defines_symbol($content, $symbol)
    {
        $content = (string) $content;
        $symbol = trim((string) $symbol);
        if ('' === $content || !$this->runtime_js_scan_is_actionable_missing_symbol($symbol)) {
            return false;
        }
        $quoted = preg_quote($symbol, '/');

        if (preg_match('/(?:^|[^A-Za-z0-9_$])(?:function|class)\s+' . $quoted . '\b/m', $content)) {
            return true;
        }

        $assignment_patterns = array(
            '/(?:^|[^A-Za-z0-9_$])(?:var|let|const)\s+' . $quoted . '\s*=\s*([^;\n]{0,240})/m',
            '/(?:^|[^A-Za-z0-9_$])' . $quoted . '\s*=\s*(?!=)([^;\n]{0,240})/m',
            '/(?:window|globalThis)\s*\.\s*' . $quoted . '\s*=\s*([^;\n]{0,240})/i',
            '/(?:window|globalThis)\s*\[\s*["\']' . $quoted . '["\']\s*\]\s*=\s*([^;\n]{0,240})/i',
        );
        foreach ($assignment_patterns as $pattern) {
            if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $rhs = isset($match[1]) ? (string) $match[1] : '';
                // x = JSON.parse(x), x = normalize(x), x = x || {} and
                // equivalent bare self-referential assignments mutate existing
                // state; they are not evidence that this file provides x.
                if (!$this->runtime_js_scan_rhs_reuses_bare_symbol($rhs, $symbol)) {
                    return true;
                }
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
        $this->runtime_js_scan_resolved_jquery_plugin_contexts = array();
        $exclusions = $this->get_runtime_js_scan_current_exclusions();
        $suggestions = array();
        $seen = array();

        $this->runtime_js_scan_add_lifecycle_dependency_risk_suggestions($suggestions, $seen, $scripts, $exclusions, true);
        $this->runtime_js_scan_add_declared_dependency_risk_suggestions($suggestions, $seen, $scripts, $exclusions, true);

        $result = $this->runtime_js_scan_finalize_strong_silent_dependency_result($suggestions);
        $this->runtime_js_scan_current_scripts = array();
        return $result;
    }

    private function runtime_js_scan_exact_error_source_inventory_scripts($source, array $scripts)
    {
        $source = $this->runtime_js_scan_sanitize_source((string) $source);
        if ('' === $source || empty($scripts)) {
            return array();
        }

        $source_clean = strtolower($this->runtime_js_scan_clean_console_candidate($source));
        $source_path = (string) wp_parse_url($source_clean, PHP_URL_PATH);
        if ('' === $source_path) {
            $source_path = $source_clean;
        }
        $source_path = strtolower('/' . ltrim((string) $source_path, '/'));

        $exact_url = array();
        $exact_path = array();
        $url_seen = array();
        $path_seen = array();

        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }

            $script_src = $this->runtime_js_scan_sanitize_source((string) ($script['src'] ?? ''));
            if ('' === $script_src) {
                continue;
            }
            $script_clean = strtolower($this->runtime_js_scan_clean_console_candidate($script_src));
            $identity = $this->runtime_js_scan_unique_loaded_script_identity($script);
            if ('' === $identity) {
                continue;
            }

            if ($script_clean === $source_clean && !isset($url_seen[$identity])) {
                $url_seen[$identity] = true;
                $exact_url[] = $script;
                continue;
            }

            $script_path = (string) wp_parse_url($script_clean, PHP_URL_PATH);
            if ('' === $script_path) {
                $script_path = $script_clean;
            }
            $script_path = strtolower('/' . ltrim((string) $script_path, '/'));
            if ($script_path === $source_path && !isset($path_seen[$identity])) {
                $path_seen[$identity] = true;
                $exact_path[] = $script;
            }
        }

        // A browser-provided full source is stronger evidence than basename or
        // fuzzy source-hint matching. Prefer the exact loaded URL first; only
        // fall back to a unique exact public path when the host/form differs.
        if (!empty($exact_url)) {
            return array_values($exact_url);
        }
        if (1 === count($exact_path)) {
            return array_values($exact_path);
        }

        return array();
    }

    /**
     * Keep the browser execution source separate from its WordPress policy owner.
     * For an inline companion the exact -before/-after segment supplies runtime
     * source evidence, while the parent registered handle remains the unit that
     * the visible JavaScript policy lists can move between lanes.
     */
    private function runtime_js_scan_error_execution_consumer_context($source, $message, $detail, array $scripts)
    {
        $source_ids = $this->runtime_js_scan_inline_frame_ids_from_text((string) $source);
        $all_ids = $source_ids;
        if (empty($all_ids)) {
            $all_ids = $this->runtime_js_scan_inline_frame_ids_from_text((string) $source . "\n" . (string) $message . "\n" . (string) $detail);
        }

        foreach ($all_ids as $inline_id) {
            $execution = $this->runtime_js_scan_find_script_by_id($scripts, $inline_id);
            if (empty($execution) || strtolower(trim((string) ($execution['id'] ?? ''))) !== strtolower(trim((string) $inline_id))) {
                continue;
            }
            $owner = $execution;
            $parent_id = $this->runtime_js_scan_related_external_id_for_inline_id($inline_id);
            if ('' !== $parent_id) {
                $parent = $this->runtime_js_scan_find_script_by_id($scripts, $parent_id);
                if (!empty($parent)) {
                    $owner = $parent;
                }
            }
            return array(
                'execution' => $execution,
                'policyOwner' => $owner,
                'inlineId' => (string) $inline_id,
                'isInlineCompanion' => true,
            );
        }

        $consumers = $this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts);
        if (empty($consumers)) {
            return array();
        }
        $consumer = (array) $consumers[0];
        return array(
            'execution' => $consumer,
            'policyOwner' => $consumer,
            'inlineId' => '',
            'isInlineCompanion' => false,
        );
    }

    private function runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, array $scripts)
    {
        $matches = array();
        $seen = array();
        $push = function ($script) use (&$matches, &$seen) {
            if (!is_array($script)) {
                return;
            }
            $key = $this->runtime_js_scan_unique_loaded_script_identity($script);
            if ('' === $key || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $matches[] = $script;
        };

        // If the browser source is a WordPress inline companion, its owning
        // enqueued script is the dependency consumer. Resolve that parent first
        // and do not let the inline pseudo-script or loose source matches dilute
        // the error-scoped WordPress dependency analysis.
        $inline_parents = array();
        $inline_text = (string) $source . "\n" . (string) $message . "\n" . (string) $detail;
        foreach ($this->runtime_js_scan_inline_frame_ids_from_text($inline_text) as $inline_id) {
            $parent_id = $this->runtime_js_scan_related_external_id_for_inline_id($inline_id);
            if ('' === $parent_id) {
                continue;
            }
            $parent = $this->runtime_js_scan_find_script_by_id($scripts, $parent_id);
            if (!empty($parent)) {
                $push($parent);
                $inline_parents[] = $parent;
            }
        }
        if (!empty($inline_parents)) {
            return array_slice($matches, 0, 6);
        }

        // When the browser gives us a concrete external script URL, resolve that
        // exact loaded script before any generic-basename/fuzzy matching. This
        // prevents unrelated files such as another theme/plugin functions.js from
        // making a known consumer look ambiguous.
        $exact_source_matches = $this->runtime_js_scan_exact_error_source_inventory_scripts($source, $scripts);
        if (!empty($exact_source_matches)) {
            return array_slice($exact_source_matches, 0, 6);
        }

        $candidates = $this->runtime_js_scan_source_candidates_from_error($source, $message, $detail);
        foreach ($this->runtime_js_scan_console_sources_from_text((string) $source . "\n" . (string) $message . "\n" . (string) $detail) as $candidate) {
            $candidates[] = $candidate;
        }
        if ('' !== trim((string) $source)) {
            array_unshift($candidates, (string) $source);
        }

        foreach ($candidates as $candidate) {
            $candidate = $this->runtime_js_scan_clean_console_candidate((string) $candidate);
            if ('' === $candidate) {
                continue;
            }
            $candidate_id = preg_replace('/(?::\d+){1,2}$/', '', $candidate);
            $direct = $this->runtime_js_scan_find_script_by_id($scripts, $candidate_id);
            if (!empty($direct)) {
                $push($direct);
            }

            // WordPress inline companions use <script-id>-before/-after. Their
            // dependency contract belongs to the parent enqueued script.
            if (preg_match('/^(.*-js)-(?:before|after)$/i', (string) $candidate_id, $inline_match)) {
                $parent = $this->runtime_js_scan_find_script_by_id($scripts, (string) $inline_match[1]);
                if (!empty($parent)) {
                    $push($parent);
                }
            }

            foreach ($this->runtime_js_scan_find_scripts_by_source_hint($candidate, $scripts) as $script) {
                $push($script);
            }
        }

        return array_slice($matches, 0, 6);
    }

    private function runtime_js_scan_add_inline_after_parent_order_suggestion(&$suggestions, &$seen, $source, $message, $detail, array $scripts, array $exclusions)
    {
        $text = (string) $source . "\n" . (string) $message . "\n" . (string) $detail;
        foreach ($this->runtime_js_scan_inline_frame_ids_from_text($text) as $inline_id) {
            if (!preg_match('/-js-after$/i', (string) $inline_id)) {
                continue;
            }

            $parent_id = $this->runtime_js_scan_related_external_id_for_inline_id($inline_id);
            if ('' === $parent_id) {
                continue;
            }
            $parent = $this->runtime_js_scan_find_script_by_id($scripts, $parent_id);
            if (empty($parent)) {
                continue;
            }

            $parent_strategy = $this->runtime_js_scan_script_effective_strategy($parent);
            $preferred_target = $this->runtime_js_scan_declared_dependency_preferred_target($parent_strategy, 'blocking', false);
            if ('' === $preferred_target) {
                // A blocking parent already executes before its inline-after
                // companion. In that case continue into the parent's declared
                // dependencies instead of blaming the parent itself.
                continue;
            }

            $suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($parent);
            if ('' === $suggestion) {
                continue;
            }
            $parent_name = sanitize_text_field((string) ($parent['handle'] ?? $parent['id'] ?? $suggestion));
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $suggestion,
                'runtime error inline-after parent ' . $parent_name,
                (string) ($parent['src'] ?? ''),
                trim((string) $message . "\n" . (string) $detail),
                'The browser error originates from WordPress inline companion "' . sanitize_text_field((string) $inline_id) . '". Its owning enqueued script "' . $parent_name . '" executes as ' . $parent_strategy . ', while the inline-after block executes immediately. The parent must execute before its own inline-after block, so this is the minimal direct execution-order fix.',
                $exclusions,
                'recommended',
                $preferred_target,
                true
            );
            return true;
        }

        return false;
    }

    private function runtime_js_scan_add_error_declared_dependency_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $scripts, array $symbols, array $exclusions)
    {
        $consumers = $this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts);
        if (empty($consumers)) {
            return false;
        }

        $conflicts = array();
        $conflict_seen = array();
        foreach ($consumers as $consumer) {
            if (empty($consumer['deps'])) {
                continue;
            }
            $consumer_strategy = $this->runtime_js_scan_script_effective_strategy($consumer);
            foreach ((array) $consumer['deps'] as $dependency_handle) {
                $provider = $this->runtime_js_scan_find_inventory_script_by_handle($dependency_handle, $scripts);
                if (empty($provider)) {
                    continue;
                }
                $provider_strategy = $this->runtime_js_scan_script_effective_strategy($provider);
                $preferred_target = $this->runtime_js_scan_declared_dependency_preferred_target($provider_strategy, $consumer_strategy, false);
                if ('' === $preferred_target) {
                    continue;
                }
                $key = strtolower((string) ($provider['handle'] ?? $dependency_handle) . '|' . (string) ($consumer['handle'] ?? $consumer['id'] ?? $consumer['src'] ?? ''));
                if (isset($conflict_seen[$key])) {
                    continue;
                }
                $conflict_seen[$key] = true;
                $conflicts[] = array(
                    'provider' => $provider,
                    'consumer' => $consumer,
                    'providerStrategy' => $provider_strategy,
                    'consumerStrategy' => $consumer_strategy,
                    'preferredTarget' => $preferred_target,
                );
            }
        }

        if (empty($conflicts)) {
            return false;
        }

        $selected = array();
        if (1 === count($conflicts)) {
            $selected = $conflicts;
        } else {
            $consumer_identities = array();
            foreach ($conflicts as $conflict) {
                $consumer_identity = $this->runtime_js_scan_script_batch_identity((array) ($conflict['consumer'] ?? array()));
                if ('' !== $consumer_identity) {
                    $consumer_identities[$consumer_identity] = true;
                }
            }

            if (1 === count($consumer_identities)) {
                // Every listed edge is a declared dependency of the exact same
                // failing consumer and every provider is proven to execute too
                // late. These are not page-wide speculative matches: all of the
                // broken direct dependency edges must be repaired to restore the
                // consumer's WordPress execution contract.
                $selected = $conflicts;
            } elseif (!empty($symbols)) {
                foreach ($conflicts as $conflict) {
                    $provider_content = $this->runtime_js_scan_script_content((array) $conflict['provider']);
                    if ('' === $provider_content) {
                        continue;
                    }
                    foreach ($symbols as $symbol) {
                        if ($this->runtime_js_scan_file_defines_symbol($provider_content, $symbol)) {
                            $selected[] = $conflict;
                            break;
                        }
                    }
                }
                // Multiple possible consumers remain ambiguous. Do not fan out
                // fixes unless concrete provider code narrows it to one edge.
                if (1 !== count($selected)) {
                    return false;
                }
            } else {
                return false;
            }
        }

        $added = false;
        foreach ($selected as $conflict) {
            $provider = (array) $conflict['provider'];
            $consumer = (array) $conflict['consumer'];
            $suggestion = $this->runtime_js_scan_dependency_suggestion_for_script($provider);
            if ('' === $suggestion) {
                continue;
            }
            $provider_name = sanitize_text_field((string) ($provider['handle'] ?? $provider['id'] ?? $suggestion));
            $consumer_name = sanitize_text_field((string) ($consumer['handle'] ?? $consumer['id'] ?? $consumer['src'] ?? 'runtime error source'));
            $delay_suggestion = $this->runtime_js_scan_delay_consumer_suggestion(
                (string) $conflict['providerStrategy'],
                (string) $conflict['consumerStrategy'],
                $consumer
            );
            $this->runtime_js_scan_add_suggestion(
                $suggestions,
                $seen,
                $suggestion,
                'runtime error dependency ' . $provider_name,
                (string) ($provider['src'] ?? ''),
                trim((string) $message . "\n" . (string) $detail),
                'The browser error maps to "' . $consumer_name . '". WordPress registered "' . $provider_name . '" as its dependency, and the final page executes that provider as ' . (string) $conflict['providerStrategy'] . ' while the failing consumer executes as ' . (string) $conflict['consumerStrategy'] . '. When the provider is delayed and the consumer is deferred, first keep the proven consumer in the delayed execution class; if that fails, promote the provider. This is the minimal error-scoped dependency conflict; unrelated page dependency edges were not considered.',
                $exclusions,
                'recommended',
                (string) $conflict['preferredTarget'],
                true,
                null,
                $delay_suggestion
            );
            $added = true;
        }

        return $added;
    }

    private function runtime_js_scan_script_batch_identity(array $script)
    {
        $handle = sanitize_key((string) ($script['handle'] ?? ''));
        if ('' !== $handle) {
            return 'handle:' . $handle;
        }

        $id = strtolower(trim((string) ($script['id'] ?? '')));
        if ('' !== $id) {
            $id = preg_replace('/-js-(?:before|after|extra|translations)$/', '-js', $id);
            return 'id:' . $id;
        }

        $src = $this->runtime_js_scan_sanitize_source((string) ($script['src'] ?? ''));
        if ('' !== $src) {
            $src = preg_replace('/[?#].*$/', '', strtolower($src));
            return 'src:' . $src;
        }

        return '';
    }

    private function runtime_js_scan_scripts_are_same_batch_identity(array $left, array $right)
    {
        $left_identity = $this->runtime_js_scan_script_batch_identity($left);
        $right_identity = $this->runtime_js_scan_script_batch_identity($right);
        return '' !== $left_identity && $left_identity === $right_identity;
    }

    private function runtime_js_scan_dependency_ancestry_contains_script(array $consumer, array $candidate, array $scripts, $max_depth = 5)
    {
        $candidate_handle = sanitize_key((string) ($candidate['handle'] ?? ''));
        if ('' === $candidate_handle) {
            return false;
        }

        $queue = array();
        $visited = array();
        foreach ((array) ($consumer['deps'] ?? array()) as $dependency_handle) {
            $dependency_handle = sanitize_key((string) $dependency_handle);
            if ('' !== $dependency_handle) {
                $queue[] = array($dependency_handle, 1);
            }
        }

        while (!empty($queue)) {
            $entry = array_shift($queue);
            $handle = sanitize_key((string) ($entry[0] ?? ''));
            $depth = (int) ($entry[1] ?? 0);
            if ('' === $handle || isset($visited[$handle]) || $depth > max(1, (int) $max_depth)) {
                continue;
            }
            $visited[$handle] = true;
            if ($handle === $candidate_handle) {
                return true;
            }

            $dependency = $this->runtime_js_scan_find_inventory_script_by_handle($handle, $scripts);
            if (empty($dependency)) {
                continue;
            }
            foreach ((array) ($dependency['deps'] ?? array()) as $nested_handle) {
                $nested_handle = sanitize_key((string) $nested_handle);
                if ('' !== $nested_handle && !isset($visited[$nested_handle])) {
                    $queue[] = array($nested_handle, $depth + 1);
                }
            }
        }

        return false;
    }

    private function runtime_js_scan_error_is_downstream_of_resolved_failure($source, $message, $detail, array $symbols, array $scripts, array $resolved_failures)
    {
        if (empty($resolved_failures)) {
            return false;
        }

        $current_consumers = $this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts);
        if (empty($current_consumers)) {
            return false;
        }

        foreach ($resolved_failures as $failure) {
            if (empty($failure['consumers']) || !is_array($failure['consumers'])) {
                continue;
            }
            foreach ($failure['consumers'] as $prior_consumer) {
                if (!is_array($prior_consumer)) {
                    continue;
                }
                foreach ($current_consumers as $current_consumer) {
                    if ($this->runtime_js_scan_scripts_are_same_batch_identity($current_consumer, $prior_consumer)) {
                        return true;
                    }
                    if ($this->runtime_js_scan_dependency_ancestry_contains_script($current_consumer, $prior_consumer, $scripts)) {
                        return true;
                    }
                }

                if (!empty($symbols)) {
                    $prior_content = $this->runtime_js_scan_script_content($prior_consumer);
                    if ('' === $prior_content) {
                        continue;
                    }
                    foreach ($symbols as $symbol) {
                        if ($this->runtime_js_scan_file_defines_symbol($prior_content, $symbol)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function runtime_js_scan_register_resolved_failure(array &$resolved_failures, $source, $message, $detail, array $scripts)
    {
        $consumers = $this->runtime_js_scan_error_consumer_inventory_scripts($source, $message, $detail, $scripts);
        if (empty($consumers)) {
            return;
        }

        $identities = array();
        foreach ($consumers as $consumer) {
            $identity = $this->runtime_js_scan_script_batch_identity((array) $consumer);
            if ('' !== $identity) {
                $identities[$identity] = true;
            }
        }
        if (empty($identities)) {
            return;
        }

        $resolved_failures[] = array(
            'consumers' => $consumers,
            'identities' => array_keys($identities),
        );
        if (count($resolved_failures) > 24) {
            $resolved_failures = array_slice($resolved_failures, -24);
        }
    }

    /**
     * Resolve an explicit jQuery-plugin runtime failure before generic WordPress
     * dependency repair. The browser-named method plus source-proven provider is
     * stronger evidence than an unrelated declared dependency that merely appears
     * in a secondary stack wrapper. All existing alias/member/provider safeguards
     * remain unchanged; this helper only centralizes their existing resolver.
     */
    private function runtime_js_scan_add_targeted_jquery_plugin_error_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $scripts, array $exclusions)
    {
        $jquery_plugin_provider_added = false;
        $jquery_plugin_calls = array_merge(
            $this->runtime_js_scan_extract_jquery_plugin_calls_from_error($message, $detail),
            $this->runtime_js_scan_extract_jquery_plugin_member_calls_from_error($message, $detail)
        );

        foreach ($jquery_plugin_calls as $jquery_call) {
            $receiver = (string) ($jquery_call['receiver'] ?? '');
            $method = (string) ($jquery_call['method'] ?? '');
            $receiver_type = (string) ($jquery_call['receiverType'] ?? 'callable');
            if ('' === $receiver || '' === $method) {
                continue;
            }

            $proven_consumer = array();
            $page_providers = array();

            if ('member' === $receiver_type) {
                $page_providers = $this->runtime_js_scan_find_jquery_plugin_provider_scripts($method, $scripts);
                $proven_consumer = $this->runtime_js_scan_find_observed_same_owner_jquery_member_consumer(
                    $receiver,
                    $method,
                    $source,
                    $message,
                    $detail,
                    $scripts,
                    $page_providers
                );
                if (empty($proven_consumer)) {
                    continue;
                }
            } elseif (!in_array(strtolower($receiver), array('$', 'jquery'), true)) {
                $proven_consumer = $this->runtime_js_scan_find_proven_jquery_alias_consumer(
                    $receiver,
                    $method,
                    $source,
                    $message,
                    $detail,
                    $scripts
                );
                if (empty($proven_consumer)) {
                    $page_providers = $this->runtime_js_scan_find_jquery_plugin_provider_scripts($method, $scripts);
                    $proven_consumer = $this->runtime_js_scan_find_observed_same_owner_jquery_plugin_consumer(
                        $receiver,
                        $method,
                        $source,
                        $message,
                        $detail,
                        $scripts,
                        $page_providers
                    );
                }
                if (empty($proven_consumer)) {
                    continue;
                }
            }

            if (empty($page_providers)) {
                $page_providers = $this->runtime_js_scan_find_jquery_plugin_provider_scripts($method, $scripts);
            }
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
                $filesystem_context,
                $proven_consumer
            )) {
                $jquery_plugin_provider_added = true;
            }
        }

        return $jquery_plugin_provider_added;
    }

    private function build_runtime_js_scan_suggestions(array $errors, array $scripts = array())
    {
        $scripts = $this->runtime_js_scan_normalize_script_inventory($scripts);
        $this->runtime_js_scan_current_scripts = $scripts;
        $exclusions = $this->get_runtime_js_scan_current_exclusions();
        $suggestions = array();
        $seen = array();
        $resolved_failures = array();

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $message = isset($error['message']) ? sanitize_text_field((string) $error['message']) : '';
            $source = isset($error['source']) ? $this->runtime_js_scan_sanitize_source((string) $error['source']) : '';
            $detail = isset($error['detail']) ? sanitize_textarea_field((string) $error['detail']) : '';
            $line = isset($error['line']) ? max(0, (int) $error['line']) : 0;
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
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            if ($this->runtime_js_scan_add_navigation_loop_suggestions($suggestions, $seen, $error, $scripts, $exclusions)) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            $direct_sources = $this->runtime_js_scan_collect_direct_stack_sources($source, $message, $detail, $scripts);
            $direct_owners = !empty($direct_sources) ? $this->runtime_js_scan_unique_direct_source_owners($direct_sources) : array();
            $symbols = $this->runtime_js_scan_extract_missing_symbols_from_error($message, $detail);

            if ($this->runtime_js_scan_add_duplicate_execution_warning($suggestions, $seen, $source, $message, $detail, $exclusions)) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            if ($this->runtime_js_scan_add_jquery_migrate_dependency_suggestions($suggestions, $seen, $source, $message, $detail, $scripts, $exclusions)) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            if ($this->runtime_js_scan_add_inline_after_parent_order_suggestion(
                $suggestions,
                $seen,
                $source,
                $message,
                $detail,
                $scripts,
                $exclusions
            )) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            /*
             * A computed global failure (for example window[r]()) needs the
             * specialized source-level resolver before generic declared
             * dependency repair. A consumer may have a valid WordPress
             * dependency that is not the provider of the computed global. If
             * generic dependency repair wins first, it can promote an
             * unrelated dependency and prevent the exact provider/consumer
             * proof from running.
             *
             * This is intentionally additive: only a uniquely proven computed
             * global provider short-circuits here. When that proof fails, the
             * existing declared-dependency and all later fixers run unchanged.
             */
            if ($this->runtime_js_scan_add_computed_window_global_provider_suggestion(
                $suggestions,
                $seen,
                $source,
                $message,
                $detail,
                $scripts,
                $exclusions
            )) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            // An explicit jQuery-plugin TypeError names the missing method itself.
            // Resolve that exact provider/consumer relation before generic declared
            // dependencies so a secondary wrapper (for example a consent plugin
            // intercepting jQuery.each) cannot preempt the real provider.
            if ($this->runtime_js_scan_add_targeted_jquery_plugin_error_suggestions(
                $suggestions,
                $seen,
                $source,
                $message,
                $detail,
                $scripts,
                $exclusions
            )) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            if ($this->runtime_js_scan_add_error_declared_dependency_suggestions(
                $suggestions,
                $seen,
                $source,
                $message,
                $detail,
                $scripts,
                $symbols,
                $exclusions
            )) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            if ($this->runtime_js_scan_add_functional_failure_console_suggestion(
                $suggestions,
                $seen,
                $source,
                $message,
                $detail,
                $scripts,
                $exclusions
            )) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            // For undefined/null property reads, inspect the exact failing source
            // first. If the source line proves the receiver expression and exactly
            // one loaded script defines that receiver, repair the provider (and one
            // explicit unique upstream prerequisite when proven) instead of blaming
            // the consumer. Fall back to precise consumer review evidence only when
            // the provider chain cannot be proven deterministically.
            if ($this->runtime_js_scan_add_undefined_property_provider_chain_suggestions(
                $suggestions,
                $seen,
                $source,
                $message,
                $detail,
                $line,
                $scripts,
                $exclusions
            )) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            if ($this->runtime_js_scan_add_undefined_property_consumer_suggestion(
                $suggestions,
                $seen,
                $source,
                $message,
                $detail,
                $line,
                $scripts,
                $exclusions
            )) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            // Computed global dispatch (for example window[r]()) is a distinct
            // provider problem: the browser error does not reveal the real global
            // name. Resolve it only when source inspection proves the exact consumer,
            // one callback/function config value in the same owner context, and one
            // earlier loaded script that actually defines that resolved global.
            if ($this->runtime_js_scan_add_computed_window_global_provider_suggestion(
                $suggestions,
                $seen,
                $source,
                $message,
                $detail,
                $scripts,
                $exclusions
            )) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            // A TypeError saying X.foo is not a function proves that X exists;
            // this is a wrong-state/wrong-type timing failure, not evidence of a
            // missing provider. Repair the exact failing consumer strategy first
            // and do not open symbol-provider discovery for this error class.
            if ($this->runtime_js_scan_add_wrong_type_consumer_strategy_suggestion(
                $suggestions,
                $seen,
                $source,
                $message,
                $detail,
                $scripts,
                $exclusions
            )) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            $dynamic_dispatch_closure_added = false;
            foreach ($symbols as $symbol) {
                if ($this->runtime_js_scan_add_dynamic_dispatch_missing_global_closure(
                    $suggestions,
                    $seen,
                    $symbol,
                    $source,
                    $message,
                    $detail,
                    $scripts,
                    $exclusions
                )) {
                    $dynamic_dispatch_closure_added = true;
                    break;
                }
            }
            if ($dynamic_dispatch_closure_added) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            $inventory_provider_added = false;
            foreach ($symbols as $symbol) {
                if ($this->runtime_js_scan_add_inventory_symbol_provider_suggestions($suggestions, $seen, $symbol, $source, $message, $detail, $scripts, $exclusions)) {
                    $inventory_provider_added = true;
                }
            }

            if ($inventory_provider_added) {
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
                continue;
            }

            // Before falling back to the failing consumer or scanning its owner,
            // consolidate errors from this same pasted batch. Suppress a later
            // fallback only when a previously resolved failure is causally linked
            // by the actual page graph or by concrete provider code evidence.
            if ($this->runtime_js_scan_error_is_downstream_of_resolved_failure(
                $source,
                $message,
                $detail,
                $symbols,
                $scripts,
                $resolved_failures
            )) {
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

                        $definition_source = isset($definition['source']) ? (string) $definition['source'] : '';
                        $loaded_provider_matches = '' !== $definition_source
                            ? $this->runtime_js_scan_find_scripts_by_source_hint($definition_source, $scripts)
                            : array();
                        $provider_is_loaded = !empty($loaded_provider_matches);

                        $this->runtime_js_scan_add_suggestion(
                            $suggestions,
                            $seen,
                            $def_fragment,
                            $provider_is_loaded ? 'same-owner loaded symbol provider' : 'same-owner codebase provider candidate',
                            $definition_source,
                            $message,
                            $provider_is_loaded
                                ? 'The error stack identifies this plugin/theme owner, and code discovery found an exact symbol provider that is also present in the scanned page inventory. Keep the loaded provider available before the direct consumer.'
                                : 'The error stack identifies this plugin/theme owner, and code discovery found a file that defines the missing symbol, but that file is not present in the scanned page inventory. Treat it as provider evidence only; do not append an execution-strategy fix for a script that is not loaded on this page.',
                            $exclusions,
                            $provider_is_loaded ? 'recommended' : 'review',
                            '',
                            false,
                            $provider_is_loaded ? null : false
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
                $this->runtime_js_scan_register_resolved_failure($resolved_failures, $source, $message, $detail, $scripts);
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

        // A later duplicate form of an error can be processed after an exact
        // jQuery provider fix has already been proven. Run one final, narrowly
        // scoped cleanup after the complete batch so those late generic source
        // fallbacks do not survive beside the exact provider fix. Existing
        // fixer logic remains unchanged; only redundant fallbacks for a proven
        // method/source context are removed.
        $suggestions = $this->runtime_js_scan_remove_late_resolved_jquery_plugin_fallbacks($suggestions);
        $this->runtime_js_scan_resolved_jquery_plugin_contexts = array();
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
