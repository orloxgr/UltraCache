<?php
/**
 * Adaptive Elementor JavaScript compatibility analysis.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Elementor_JS_Compatibility_Trait
{
    /**
     * Build a version/install signature for the persistent Elementor knowledge map.
     *
     * The signature intentionally follows the installed Elementor ecosystem rather
     * than a hard-coded UltraCache compatibility table. Relevant plugin main-file
     * mtimes are included so addon upgrades invalidate the map even when a runtime
     * version constant is unavailable.
     *
     * @return string
     */
    private function get_elementor_js_compatibility_signature()
    {
        $parts = array(
            // Bump when the compatibility evidence model changes so stale
            // positive classifications cannot keep scripts on the DCL path.
            'policy=2',
            'elementor=' . (defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : 'none'),
            'elementor-pro=' . (defined('ELEMENTOR_PRO_VERSION') ? (string) ELEMENTOR_PRO_VERSION : 'none'),
        );

        $active_plugins = (array) get_option('active_plugins', array());
        if (function_exists('is_multisite') && is_multisite()) {
            $active_plugins = array_merge(
                $active_plugins,
                array_keys((array) get_site_option('active_sitewide_plugins', array()))
            );
        }
        $active_plugins = array_values(array_unique(array_map('strval', $active_plugins)));
        sort($active_plugins, SORT_STRING);

        $plugins_root = function_exists('ultracache_plugins_root_dir') ? ultracache_plugins_root_dir() : '';
        $plugins_root = is_string($plugins_root) ? trailingslashit(wp_normalize_path($plugins_root)) : '';

        foreach ($active_plugins as $plugin_file) {
            $plugin_file = wp_normalize_path((string) $plugin_file);
            $plugin_lc = strtolower($plugin_file);
            if (false === strpos($plugin_lc, 'elementor')
                && false === strpos($plugin_lc, 'header-footer')
                && false === strpos($plugin_lc, '/hfe')) {
                continue;
            }

            $mtime = 0;
            if ('' !== $plugins_root) {
                $candidate = $plugins_root . ltrim($plugin_file, '/');
                $candidate_mtime = function_exists('ultracache_safe_filemtime') ? ultracache_safe_filemtime($candidate, 'elementor_compatibility_signature') : false;
                if (false !== $candidate_mtime) {
                    $mtime = (int) $candidate_mtime;
                }
            }
            $parts[] = $plugin_file . '@' . $mtime;
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Load the persistent knowledge map for the current Elementor installation.
     *
     * @return array<string,mixed>
     */
    private function get_elementor_js_compatibility_knowledge()
    {
        $signature = $this->get_elementor_js_compatibility_signature();
        $knowledge = get_option('ultracache_elementor_js_compatibility_knowledge', array());
        if (!is_array($knowledge) || (string) ($knowledge['signature'] ?? '') !== $signature) {
            return array(
                'signature' => $signature,
                'widgets' => array(),
                'scripts' => array(),
                'updatedAt' => 0,
            );
        }

        $knowledge['widgets'] = isset($knowledge['widgets']) && is_array($knowledge['widgets']) ? $knowledge['widgets'] : array();
        $knowledge['scripts'] = isset($knowledge['scripts']) && is_array($knowledge['scripts']) ? $knowledge['scripts'] : array();
        return $knowledge;
    }

    /**
     * Persist updated Elementor compatibility knowledge through WordPress options.
     *
     * @param array<string,mixed> $knowledge Knowledge map.
     * @return void
     */
    private function save_elementor_js_compatibility_knowledge(array $knowledge)
    {
        $knowledge['updatedAt'] = time();
        update_option('ultracache_elementor_js_compatibility_knowledge', $knowledge, false);
    }

    /**
     * Normalize one Elementor widget identifier.
     *
     * @param string $widget Widget identifier.
     * @return string
     */
    private function normalize_elementor_compatibility_widget_name($widget)
    {
        $widget = strtolower(trim((string) $widget));
        if (false !== strpos($widget, '.')) {
            $widget = (string) strtok($widget, '.');
        }
        return sanitize_key($widget);
    }

    /**
     * Discover the actual Elementor widget types rendered in one HTML response.
     *
     * @param string $html Frontend HTML.
     * @return array<int,string>
     */
    private function collect_rendered_elementor_compatibility_widgets($html)
    {
        $html = is_string($html) ? $html : '';
        if ('' === $html || false === stripos($html, 'elementor')) {
            return array();
        }

        $widgets = array();
        if (preg_match_all('/\bdata-widget_type\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $raw_widget) {
                $widget = $this->normalize_elementor_compatibility_widget_name(
                    html_entity_decode((string) $raw_widget, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                );
                if ('' !== $widget) {
                    $widgets[$widget] = true;
                }
            }
        }

        if (preg_match_all('/\belementor-widget-([a-z0-9_-]+)/i', $html, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $raw_widget) {
                $widget = $this->normalize_elementor_compatibility_widget_name($raw_widget);
                if ('' !== $widget) {
                    $widgets[$widget] = true;
                }
            }
        }

        return array_keys($widgets);
    }

    /**
     * Return live script dependencies declared by rendered Elementor widgets.
     *
     * This uses Elementor's supported widget contract instead of reproducing its
     * dependency lists inside UltraCache.
     *
     * @param array<int,string> $widgets Rendered widget names.
     * @return array<string,array<int,string>>
     */
    private function collect_live_elementor_widget_script_dependencies(array $widgets)
    {
        $result = array();
        if (empty($widgets) || !class_exists('Elementor\\Plugin') || !method_exists('Elementor\\Plugin', 'instance')) {
            return $result;
        }

        try {
            $elementor = \Elementor\Plugin::instance();
        } catch (Throwable $throwable) {
            return $result;
        }

        if (!is_object($elementor)
            || !isset($elementor->widgets_manager)
            || !is_object($elementor->widgets_manager)
            || !method_exists($elementor->widgets_manager, 'get_widget_types')) {
            return $result;
        }

        foreach ($widgets as $widget_name) {
            $widget_name = $this->normalize_elementor_compatibility_widget_name($widget_name);
            if ('' === $widget_name) {
                continue;
            }

            try {
                $widget = $elementor->widgets_manager->get_widget_types($widget_name);
            } catch (Throwable $throwable) {
                $widget = null;
            }

            if (!is_object($widget) || !method_exists($widget, 'get_script_depends')) {
                continue;
            }

            try {
                $dependencies = $widget->get_script_depends();
            } catch (Throwable $throwable) {
                $dependencies = array();
            }

            if (!is_array($dependencies)) {
                continue;
            }

            foreach ($dependencies as $dependency) {
                $dependency = sanitize_key((string) $dependency);
                if ('' !== $dependency) {
                    $result[$widget_name][$dependency] = true;
                }
            }
        }

        foreach ($result as $widget_name => $dependencies) {
            $result[$widget_name] = array_keys($dependencies);
        }

        return $result;
    }

    /**
     * Return whether a local JavaScript source contains immediate user-interaction
     * binding evidence. These are deliberately event-binding patterns rather than
     * broad words such as menu, frontend, or widget.
     *
     * @param string $content JavaScript source.
     * @return bool
     */
    private function elementor_compatibility_source_has_interaction_evidence($content)
    {
        $content = is_string($content) ? $content : '';
        if ('' === $content) {
            return false;
        }

        $events = '(?:click|dblclick|mousedown|mouseup|pointerdown|pointerup|touchstart|touchend|keydown|keyup|keypress|submit|change|input|focus|blur)';
        $patterns = array(
            '/\.on\s*\(\s*["\']' . $events . '(?:\.[^"\']+)?["\']/i',
            '/\.one\s*\(\s*["\']' . $events . '(?:\.[^"\']+)?["\']/i',
            '/\.on\s*\([\s\S]{0,900}?["\']' . $events . '["\']\s*:/i',
            '/\.one\s*\([\s\S]{0,900}?["\']' . $events . '["\']\s*:/i',
            '/\.on\s*\([\s\S]{0,900}?\b' . $events . '\s*:/i',
            '/\.one\s*\([\s\S]{0,900}?\b' . $events . '\s*:/i',
            '/addEventListener\s*\(\s*["\']' . $events . '["\']/i',
            '/\bon(?:click|dblclick|mousedown|mouseup|pointerdown|pointerup|touchstart|touchend|keydown|keyup|keypress|submit|change|input|focus|blur)\s*=/i',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map Elementor element_ready hooks to interaction evidence found only in
     * that hook's local source segment. This avoids treating one interaction
     * listener elsewhere in a shared bootstrap as evidence for every widget in
     * the file.
     *
     * @param string $content JavaScript source.
     * @return array<string,array{interactive:bool,evidence:string}>
     */
    private function collect_elementor_compatibility_element_ready_widget_map($content)
    {
        $content = is_string($content) ? $content : '';
        if ('' === $content) {
            return array();
        }

        if (!preg_match_all(
            '#frontend/element_ready/([a-z0-9_-]+)\.default#i',
            $content,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return array();
        }

        $result = array();
        $match_count = count($matches);
        $content_length = strlen($content);

        foreach ($matches as $index => $match) {
            $widget = $this->normalize_elementor_compatibility_widget_name((string) ($match[1][0] ?? ''));
            $start = isset($match[0][1]) ? max(0, (int) $match[0][1]) : 0;
            if ('' === $widget) {
                continue;
            }

            $next_start = $content_length;
            if (($index + 1) < $match_count && isset($matches[$index + 1][0][1])) {
                $next_start = max($start, (int) $matches[$index + 1][0][1]);
            }

            // Keep the probe bounded even in a very large generated bootstrap.
            $segment_length = min(6000, max(0, $next_start - $start));
            if (0 === $segment_length) {
                $segment_length = min(6000, max(0, $content_length - $start));
            }
            $segment = substr($content, $start, $segment_length);
            $interactive = $this->elementor_compatibility_source_has_interaction_evidence($segment);

            if (!isset($result[$widget]) || $interactive) {
                $result[$widget] = array(
                    'interactive' => $interactive,
                    'evidence' => $interactive ? 'element-ready-local-interaction' : 'element-ready',
                );
            }
        }

        return $result;
    }


    /**
     * Resolve an Elementor webpack source-comment module path to the installed
     * plugin filesystem. Elementor Pro's generated elements-handlers source keeps
     * module paths such as ../modules/nav-menu/... which are rooted at the plugin.
     *
     * @param string $bootstrap_path Local bootstrap file.
     * @param string $module_path    Webpack module path.
     * @return string
     */
    private function resolve_elementor_compatibility_module_path($bootstrap_path, $module_path)
    {
        $plugins_root = function_exists('ultracache_plugins_root_dir') ? ultracache_plugins_root_dir() : '';
        $plugins_root = is_string($plugins_root) ? trailingslashit(wp_normalize_path($plugins_root)) : '';
        $bootstrap_path = wp_normalize_path((string) $bootstrap_path);
        $module_path = wp_normalize_path((string) $module_path);
        if ('' === $plugins_root || '' === $bootstrap_path || '' === $module_path || 0 !== strpos($bootstrap_path, $plugins_root)) {
            return '';
        }

        $relative_bootstrap = ltrim(substr($bootstrap_path, strlen($plugins_root)), '/');
        $segments = explode('/', $relative_bootstrap);
        $plugin_slug = sanitize_key((string) ($segments[0] ?? ''));
        if ('' === $plugin_slug) {
            return '';
        }

        while (0 === strpos($module_path, '../')) {
            $module_path = substr($module_path, 3);
        }
        $module_path = ltrim($module_path, '/');
        if ('' === $module_path) {
            return '';
        }

        $candidate = wp_normalize_path($plugins_root . $plugin_slug . '/' . $module_path);
        $candidate_size = function_exists('ultracache_safe_filesize') ? ultracache_safe_filesize($candidate, 'elementor_compatibility_module_probe') : false;
        if (false === $candidate_size) {
            return '';
        }

        return $candidate;
    }

    /**
     * Resolve one or more Elementor webpack chunk bundle files from the directory
     * that contains the registered bootstrap asset.
     *
     * @param string            $bootstrap_path Local bootstrap path.
     * @param array<int,string> $chunk_names    Webpack chunk names.
     * @return array<int,string>
     */
    private function resolve_elementor_compatibility_chunk_bundle_paths($bootstrap_path, array $chunk_names)
    {
        $bootstrap_path = wp_normalize_path((string) $bootstrap_path);
        $directory = trailingslashit(wp_normalize_path(dirname($bootstrap_path)));
        if ('' === $bootstrap_path || '' === $directory) {
            return array();
        }

        $filesystem = function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : false;
        if (!$filesystem || !method_exists($filesystem, 'dirlist')) {
            return array();
        }

        $entries = $filesystem->dirlist($directory, false, false);
        if (!is_array($entries) || empty($entries)) {
            return array();
        }

        $resolved = array();
        foreach ($chunk_names as $chunk_name) {
            $chunk_name = trim((string) $chunk_name);
            if ('' === $chunk_name) {
                continue;
            }
            $quoted = preg_quote($chunk_name, '/');
            $preferred = '';
            $fallback = '';
            foreach ($entries as $entry_name => $entry) {
                $entry_name = (string) $entry_name;
                if (1 !== preg_match('/^' . $quoted . '\.[a-f0-9]+\.bundle(?:\.min)?\.js$/i', $entry_name)) {
                    continue;
                }
                $candidate = $directory . $entry_name;
                $candidate_size = function_exists('ultracache_safe_filesize') ? ultracache_safe_filesize($candidate, 'elementor_compatibility_chunk_probe') : false;
                if (false === $candidate_size || (int) $candidate_size > 2097152) {
                    continue;
                }
                if (false === stripos($entry_name, '.min.js')) {
                    $preferred = $candidate;
                    break;
                }
                if ('' === $fallback) {
                    $fallback = $candidate;
                }
            }
            $selected = '' !== $preferred ? $preferred : $fallback;
            if ('' !== $selected) {
                $resolved[wp_normalize_path($selected)] = true;
            }
        }

        return array_keys($resolved);
    }

    /**
     * Inspect one Elementor widget-handler mapping through either the original
     * module source (when shipped) or its generated webpack chunk bundle.
     *
     * @param string $widget        Widget name.
     * @param string $module        Webpack source-comment module path.
     * @param string $segment       Bootstrap source segment for this mapping.
     * @param string $analysis_path Local bootstrap path.
     * @return array{interactive:bool,evidence:string}
     */
    private function analyze_elementor_compatibility_handler_mapping($widget, $module, $segment, $analysis_path)
    {
        $widget = $this->normalize_elementor_compatibility_widget_name($widget);
        $module = trim((string) $module);
        $segment = (string) $segment;
        $analysis_path = wp_normalize_path((string) $analysis_path);
        if ('' === $widget || '' === $module || '' === $analysis_path) {
            return array('interactive' => false, 'evidence' => 'handler-mapping');
        }

        $handler_path = $this->resolve_elementor_compatibility_module_path($analysis_path, $module);
        $interactive = false;
        $handler_size = '' !== $handler_path && function_exists('ultracache_safe_filesize')
            ? ultracache_safe_filesize($handler_path, 'elementor_compatibility_handler_probe')
            : false;
        if (false !== $handler_size && (int) $handler_size <= 1048576) {
            $handler_source = ultracache_guarded_asset_file_get_contents($handler_path, 'js', 'elementor_js_handler_compatibility_scan', true);
            $interactive = is_string($handler_source) && $this->elementor_compatibility_source_has_interaction_evidence($handler_source);
        }

        if (!$interactive) {
            $chunk_names = array();
            if (preg_match_all('/__webpack_require__\.e\([\s\S]{0,260}?["\']([^"\']+)["\']\)/i', $segment, $chunk_matches)) {
                foreach ((array) ($chunk_matches[1] ?? array()) as $chunk_name) {
                    $chunk_name = trim((string) $chunk_name);
                    if ('' !== $chunk_name) {
                        $chunk_names[$chunk_name] = true;
                    }
                }
            }
            foreach ($this->resolve_elementor_compatibility_chunk_bundle_paths($analysis_path, array_keys($chunk_names)) as $bundle_path) {
                $bundle_source = ultracache_guarded_asset_file_get_contents($bundle_path, 'js', 'elementor_js_chunk_compatibility_scan', true);
                if (is_string($bundle_source) && $this->elementor_compatibility_source_has_interaction_evidence($bundle_source)) {
                    $interactive = true;
                    break;
                }
            }
        }

        return array(
            'interactive' => $interactive,
            'evidence' => $interactive ? 'handler-mapping+interaction-binding' : 'handler-mapping',
        );
    }

    /**
     * Analyze one registered Elementor-ecosystem script and cache the result by
     * local-file fingerprint.
     *
     * @param string               $handle    Registered handle.
     * @param object               $registered WP script dependency object.
     * @param array<string,mixed>  $knowledge       Persistent knowledge map.
     * @param bool                 $force_candidate Treat a live widget dependency or Elementor-dependent addon as a scan candidate.
     * @return array<string,mixed>
     */
    private function analyze_elementor_compatibility_registered_script($handle, $registered, array &$knowledge, $force_candidate = false)
    {
        $handle = sanitize_key((string) $handle);
        $src = is_object($registered) && isset($registered->src) ? (string) $registered->src : '';
        if ('' === $handle || '' === $src) {
            return array();
        }

        $absolute_src = $this->absolutize_public_resource_url($src, home_url('/'));
        if ('' === $absolute_src) {
            $absolute_src = $src;
        }

        $src_probe = strtolower($absolute_src . ' ' . $handle);
        $default_candidate = (bool) $force_candidate
            || false !== strpos($src_probe, 'elementor')
            || false !== strpos($src_probe, 'header-footer')
            || false !== strpos($src_probe, 'hfe-');
        $is_candidate = (bool) apply_filters('ultracache_elementor_compatibility_script_scan_candidate', $default_candidate, $handle, $absolute_src);
        if (!$is_candidate) {
            return array();
        }

        $local_path = $this->resolve_local_path_from_public_url($absolute_src);
        if ('' === $local_path) {
            return array();
        }

        $size_value = function_exists('ultracache_safe_filesize') ? ultracache_safe_filesize($local_path, 'elementor_compatibility_script_probe') : false;
        $mtime_value = function_exists('ultracache_safe_filemtime') ? ultracache_safe_filemtime($local_path, 'elementor_compatibility_script_probe') : false;
        if (false === $size_value || false === $mtime_value) {
            return array();
        }
        $size = (int) $size_value;
        $mtime = (int) $mtime_value;
        if ($size < 1 || $size > 2097152) {
            return array();
        }

        // Prefer the distributable unminified sibling for deterministic source
        // analysis when it exists. Elementor/Pro ship these alongside the served
        // .min.js assets, preserving attachHandler module paths and readable event
        // bindings without changing what the visitor downloads.
        $analysis_path = $local_path;
        if (preg_match('/\.min\.js$/i', $local_path)) {
            $unminified = (string) preg_replace('/\.min\.js$/i', '.js', $local_path);
            $unminified_size = '' !== $unminified && function_exists('ultracache_safe_filesize')
                ? ultracache_safe_filesize($unminified, 'elementor_compatibility_unminified_probe')
                : false;
            if (false !== $unminified_size && (int) $unminified_size <= 2097152) {
                $analysis_path = $unminified;
            }
        }

        $analysis_size = (int) (function_exists('ultracache_safe_filesize') ? ultracache_safe_filesize($analysis_path, 'elementor_compatibility_analysis_probe') : 0);
        $analysis_mtime = (int) (function_exists('ultracache_safe_filemtime') ? ultracache_safe_filemtime($analysis_path, 'elementor_compatibility_analysis_probe') : 0);
        $cache_key = hash('sha256', $handle . '|' . wp_normalize_path($local_path));
        $fingerprint = $mtime . ':' . $size . '|' . $analysis_mtime . ':' . $analysis_size;
        if (isset($knowledge['scripts'][$cache_key])
            && is_array($knowledge['scripts'][$cache_key])
            && (string) ($knowledge['scripts'][$cache_key]['fingerprint'] ?? '') === $fingerprint) {
            return $knowledge['scripts'][$cache_key];
        }

        $content = ultracache_guarded_asset_file_get_contents($analysis_path, 'js', 'elementor_js_compatibility_scan', true);
        if (!is_string($content) || '' === $content) {
            return array();
        }

        $widget_map = array();

        /*
         * Shared bootstrap files often register many element_ready widgets.
         * Interaction evidence is therefore scoped to each hook's local source
         * segment instead of promoting every widget because the file contains
         * one unrelated click/pointer/key binding somewhere else.
         */
        foreach ($this->collect_elementor_compatibility_element_ready_widget_map($content) as $widget => $mapping) {
            $widget_map[$widget] = $mapping;
        }

        if (preg_match_all('/attachHandler\(\s*["\']([^"\']+)["\'][\s\S]{0,1400}?["\'](\.\.\/modules\/[^"\']+\.js)["\']/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $widget = $this->normalize_elementor_compatibility_widget_name((string) ($match[1] ?? ''));
                $module = (string) ($match[2] ?? '');
                if ('' === $widget || '' === $module) {
                    continue;
                }

                $mapping = $this->analyze_elementor_compatibility_handler_mapping(
                    $widget,
                    $module,
                    (string) ($match[0] ?? ''),
                    $analysis_path
                );
                $interactive = !empty($mapping['interactive']);

                if (!isset($widget_map[$widget]) || $interactive) {
                    $widget_map[$widget] = array(
                        'interactive' => $interactive,
                        'evidence' => $interactive ? 'attach-handler+interaction-binding' : 'attach-handler',
                    );
                }
            }
        }

        // Elementor Core registers many widget handlers in the
        // elementsHandlers registry rather than through attachHandler() calls in
        // the bootstrap source. Parse both the initial object map and later
        // conditional assignments, then inspect their installed chunk bundles.
        $core_mapping_patterns = array(
            '/["\']([a-z0-9_-]+)\.default["\']\s*:\s*\(\)\s*=>[\s\S]{0,1400}?["\'](\.\.\/(?:assets|modules)\/[^"\']+\.js)["\']/i',
            '/elementsHandlers\[["\']([a-z0-9_-]+)\.default["\']\]\s*=\s*\(\)\s*=>[\s\S]{0,1400}?["\'](\.\.\/(?:assets|modules)\/[^"\']+\.js)["\']/i',
        );
        foreach ($core_mapping_patterns as $core_mapping_pattern) {
            if (!preg_match_all($core_mapping_pattern, $content, $core_matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($core_matches as $match) {
                $widget = $this->normalize_elementor_compatibility_widget_name((string) ($match[1] ?? ''));
                $module = (string) ($match[2] ?? '');
                if ('' === $widget || '' === $module) {
                    continue;
                }
                $mapping = $this->analyze_elementor_compatibility_handler_mapping(
                    $widget,
                    $module,
                    (string) ($match[0] ?? ''),
                    $analysis_path
                );
                $interactive = !empty($mapping['interactive']);
                if (!isset($widget_map[$widget]) || $interactive) {
                    $widget_map[$widget] = array(
                        'interactive' => $interactive,
                        'evidence' => $interactive ? 'core-handler-registry+interaction-binding' : 'core-handler-registry',
                    );
                }
            }
        }

        $analysis = array(
            'handle' => $handle,
            'srcPath' => (string) wp_parse_url($absolute_src, PHP_URL_PATH),
            'fingerprint' => $fingerprint,
            'widgets' => $widget_map,
            'selfInteractive' => $this->elementor_compatibility_source_has_interaction_evidence($content),
        );
        $knowledge['scripts'][$cache_key] = $analysis;
        return $analysis;
    }

    /**
     * Return whether a registered script is connected to the installed Elementor
     * ecosystem through its WordPress dependency graph.
     *
     * This catches addon runtimes whose plugin/handle name does not contain the
     * word "elementor" but which correctly declare a dependency on Elementor's
     * registered frontend assets.
     *
     * @param string            $handle     Registered script handle.
     * @param object|null       $wp_scripts WP_Scripts instance.
     * @param int               $depth      Current traversal depth.
     * @param array<string,bool> $visited   Visited handles.
     * @return bool
     */
    private function registered_script_depends_on_elementor_ecosystem($handle, $wp_scripts, $depth = 0, array $visited = array())
    {
        $handle = sanitize_key((string) $handle);
        if ('' === $handle || !is_object($wp_scripts) || $depth > 3 || !isset($wp_scripts->registered[$handle]) || !is_object($wp_scripts->registered[$handle])) {
            return false;
        }
        if (isset($visited[$handle])) {
            return false;
        }
        $visited[$handle] = true;

        $registered = $wp_scripts->registered[$handle];
        $dependencies = isset($registered->deps) && is_array($registered->deps) ? $registered->deps : array();
        foreach ($dependencies as $dependency) {
            $dependency = sanitize_key((string) $dependency);
            if ('' === $dependency || !isset($wp_scripts->registered[$dependency]) || !is_object($wp_scripts->registered[$dependency])) {
                continue;
            }

            $dependency_src = isset($wp_scripts->registered[$dependency]->src) ? (string) $wp_scripts->registered[$dependency]->src : '';
            $probe = strtolower($dependency . ' ' . $dependency_src);
            if (false !== strpos($probe, 'elementor') || false !== strpos($probe, 'header-footer') || false !== strpos($probe, 'hfe-')) {
                return true;
            }

            if ($this->registered_script_depends_on_elementor_ecosystem($dependency, $wp_scripts, $depth + 1, $visited)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Collect script handles that are actually relevant to the current response.
     *
     * Elementor registers a large catalog of assets that may never be printed on
     * the page. Restrict source analysis to handles visible in the rendered HTML,
     * WordPress' current enqueue/printed queues, and dependencies declared by the
     * rendered widgets. This keeps the first knowledge build bounded per page.
     *
     * @param string                           $html              Frontend HTML.
     * @param object|null                      $wp_scripts        WP_Scripts instance.
     * @param array<string,array<int,string>>  $live_dependencies Widget dependencies.
     * @return array<int,string>
     */
    private function collect_current_elementor_compatibility_script_handles($html, $wp_scripts, array $live_dependencies)
    {
        $handles = array();
        $html = is_string($html) ? $html : '';

        if ('' !== $html && preg_match_all('/\bdata-ultracache-handle\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $handle) {
                $handle = sanitize_key((string) $handle);
                if ('' !== $handle) {
                    $handles[$handle] = true;
                }
            }
        }

        if ('' !== $html && preg_match_all('/<script\b[^>]*\bid\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $id) {
                $id = sanitize_key((string) $id);
                if (substr($id, -3) === '-js') {
                    $id = substr($id, 0, -3);
                }
                if ('' !== $id) {
                    $handles[$id] = true;
                }
            }
        }

        if (is_object($wp_scripts)) {
            foreach (array('queue', 'to_do', 'done') as $property) {
                if (!isset($wp_scripts->{$property}) || !is_array($wp_scripts->{$property})) {
                    continue;
                }
                foreach ($wp_scripts->{$property} as $handle) {
                    $handle = sanitize_key((string) $handle);
                    if ('' !== $handle) {
                        $handles[$handle] = true;
                    }
                }
            }
        }

        foreach ($live_dependencies as $dependencies) {
            if (!is_array($dependencies)) {
                continue;
            }
            foreach ($dependencies as $handle) {
                $handle = sanitize_key((string) $handle);
                if ('' !== $handle) {
                    $handles[$handle] = true;
                }
            }
        }

        return array_keys($handles);
    }


    /**
     * Resolve an adaptive, per-page Elementor compatibility policy.
     *
     * The policy combines rendered widget evidence, Elementor's live
     * get_script_depends() contract, installed JavaScript handler mappings, and
     * actual interaction-binding source. Only the runtime/dependency handles
     * required by interaction-critical widgets on this response are protected.
     *
     * @param string              $html     Frontend HTML.
     * @param array<string,mixed> $settings Engine settings.
     * @return array<string,mixed>
     */
    private function apply_elementor_js_compatibility_context($html, array $settings)
    {
        if (empty($settings['protect_elementor_compatibility'])) {
            return $settings;
        }

        $widgets = $this->collect_rendered_elementor_compatibility_widgets($html);
        if (empty($widgets)) {
            $settings['_elementor_compatibility_protected_handles'] = array();
            $settings['_elementor_compatibility_protected_src_paths'] = array();
            return $settings;
        }

        $knowledge = $this->get_elementor_js_compatibility_knowledge();
        $knowledge_before = wp_json_encode($knowledge);
        $live_dependencies = $this->collect_live_elementor_widget_script_dependencies($widgets);
        $wp_scripts = function_exists('wp_scripts') ? wp_scripts() : null;
        $analyses = array();

        if (is_object($wp_scripts) && isset($wp_scripts->registered) && is_array($wp_scripts->registered)) {
            $live_dependency_handles = array();
            foreach ($live_dependencies as $dependencies) {
                foreach ((array) $dependencies as $dependency) {
                    $dependency = sanitize_key((string) $dependency);
                    if ('' !== $dependency) {
                        $live_dependency_handles[$dependency] = true;
                    }
                }
            }

            $current_handles = $this->collect_current_elementor_compatibility_script_handles($html, $wp_scripts, $live_dependencies);
            foreach ($current_handles as $handle) {
                if (!isset($wp_scripts->registered[$handle]) || !is_object($wp_scripts->registered[$handle])) {
                    continue;
                }
                $force_candidate = isset($live_dependency_handles[$handle])
                    || $this->registered_script_depends_on_elementor_ecosystem($handle, $wp_scripts);
                $analysis = $this->analyze_elementor_compatibility_registered_script(
                    $handle,
                    $wp_scripts->registered[$handle],
                    $knowledge,
                    $force_candidate
                );
                if (!empty($analysis)) {
                    $analyses[] = $analysis;
                }
            }
        }

        $protected = array();
        $protected_src_paths = array();
        $rendered = array_fill_keys($widgets, true);

        foreach ($widgets as $widget) {
            $widget = $this->normalize_elementor_compatibility_widget_name($widget);
            if ('' === $widget) {
                continue;
            }

            $widget_dependencies = isset($live_dependencies[$widget]) && is_array($live_dependencies[$widget])
                ? $live_dependencies[$widget]
                : array();
            $bootstrap_handles = array();
            $interactive = false;
            $evidence = array();

            foreach ($analyses as $analysis) {
                $mapped_widgets = isset($analysis['widgets']) && is_array($analysis['widgets']) ? $analysis['widgets'] : array();
                if (!isset($mapped_widgets[$widget]) || !is_array($mapped_widgets[$widget])) {
                    continue;
                }

                if (!empty($mapped_widgets[$widget]['interactive'])) {
                    $bootstrap_handle = sanitize_key((string) ($analysis['handle'] ?? ''));
                    if ('' !== $bootstrap_handle) {
                        $bootstrap_handles[$bootstrap_handle] = true;
                    }
                    $interactive = true;
                    $evidence[] = sanitize_key((string) ($mapped_widgets[$widget]['evidence'] ?? 'handler-interaction'));
                }
            }

            // A widget-declared dependency that itself binds immediate input is
            // sufficient interaction evidence even when the bootstrap mapping is
            // generated/minified beyond deterministic source reconstruction.
            if (!$interactive && !empty($widget_dependencies) && is_object($wp_scripts)) {
                foreach ($widget_dependencies as $dependency) {
                    $dependency = sanitize_key((string) $dependency);
                    if ('' === $dependency || empty($wp_scripts->registered[$dependency]) || !is_object($wp_scripts->registered[$dependency])) {
                        continue;
                    }
                    $dependency_analysis = $this->analyze_elementor_compatibility_registered_script($dependency, $wp_scripts->registered[$dependency], $knowledge, true);
                    if (!empty($dependency_analysis['selfInteractive'])) {
                        $interactive = true;
                        $evidence[] = 'declared-dependency-interaction';
                        break;
                    }
                }
            }

            $previous = isset($knowledge['widgets'][$widget]) && is_array($knowledge['widgets'][$widget])
                ? $knowledge['widgets'][$widget]
                : array();

            // Reuse positive knowledge from the same installed-version signature.
            // Negative results are not sticky: later pages/addons can add stronger
            // handler evidence and promote the widget safely.
            if (!$interactive && !empty($previous['interactive'])) {
                $interactive = true;
                $evidence[] = 'persistent-versioned-knowledge';
            }

            $merged_dependencies = array();
            foreach (array_merge((array) ($previous['dependencies'] ?? array()), $widget_dependencies) as $dependency) {
                $dependency = sanitize_key((string) $dependency);
                if ('' !== $dependency) {
                    $merged_dependencies[$dependency] = true;
                }
            }
            $merged_bootstraps = array();
            foreach (array_merge((array) ($previous['bootstrapHandles'] ?? array()), array_keys($bootstrap_handles)) as $bootstrap_handle) {
                $bootstrap_handle = sanitize_key((string) $bootstrap_handle);
                if ('' !== $bootstrap_handle) {
                    $merged_bootstraps[$bootstrap_handle] = true;
                }
            }

            $knowledge['widgets'][$widget] = array(
                'interactive' => $interactive,
                'dependencies' => array_keys($merged_dependencies),
                'bootstrapHandles' => array_keys($merged_bootstraps),
                'evidence' => array_values(array_unique(array_filter(array_merge((array) ($previous['evidence'] ?? array()), $evidence)))),
            );

            if (!$interactive) {
                continue;
            }

            foreach (array_keys($merged_dependencies) as $dependency) {
                $protected[$dependency] = true;
            }
            foreach (array_keys($merged_bootstraps) as $bootstrap_handle) {
                $protected[$bootstrap_handle] = true;
            }
        }

        if (is_object($wp_scripts) && isset($wp_scripts->registered) && is_array($wp_scripts->registered)) {
            foreach (array_keys($protected) as $handle) {
                if (!isset($wp_scripts->registered[$handle]) || !is_object($wp_scripts->registered[$handle])) {
                    continue;
                }
                $src = isset($wp_scripts->registered[$handle]->src) ? (string) $wp_scripts->registered[$handle]->src : '';
                $path = (string) wp_parse_url($src, PHP_URL_PATH);
                if ('' !== $path) {
                    $protected_src_paths[strtolower($path)] = true;
                }
            }
        }

        $settings['_elementor_compatibility_protected_handles'] = array_keys($protected);
        $settings['_elementor_compatibility_protected_src_paths'] = array_keys($protected_src_paths);
        $settings['_elementor_compatibility_rendered_widgets'] = array_keys($rendered);

        $knowledge_after = wp_json_encode($knowledge);
        if ($knowledge_after !== $knowledge_before) {
            $this->save_elementor_js_compatibility_knowledge($knowledge);
        }

        return $settings;
    }

    /**
     * Load the Elementor-only lazy-background recovery helper only when the
     * explicit Elementor Compatibility switch is enabled and the Delay runtime
     * is active for this request.
     */
    public function enqueue_elementor_compatibility_runtime_helper()
    {
        $settings = $this->get_settings();
        if (empty($settings['protect_elementor_compatibility'])) {
            return;
        }
        if (!method_exists($this, 'ultracache_is_frontend_runtime_module_requested')
            || !$this->ultracache_is_frontend_runtime_module_requested('ultracache-delayed-js-loader')
            || !method_exists($this, 'ultracache_enqueue_frontend_js_helper')) {
            return;
        }

        $this->ultracache_enqueue_frontend_js_helper(
            'ultracache-elementor-compatibility-runtime',
            'elementor-compatibility-runtime.js',
            array(),
            false
        );
    }

}
