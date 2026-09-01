<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Frontend_Assets_Trait
{
    /**
     * Normalize a plugin-owned frontend JavaScript asset path.
     *
     * The staged reviewer-cleanup migration moves UltraCache runtime helpers from
     * raw inline script output into files under assets/js/ and loads them through
     * WordPress enqueue APIs. This helper keeps those handles/URLs centralized.
     *
     * @param string $relative_path Relative path below assets/js/.
     * @return string
     */
    protected function ultracache_normalize_frontend_js_asset_path($relative_path)
    {
        $relative_path = str_replace('\\', '/', (string) $relative_path);
        $relative_path = ltrim($relative_path, '/');
        $parts = array();

        foreach (explode('/', $relative_path) as $part) {
            $part = trim((string) $part);
            if ('' === $part || '.' === $part || '..' === $part) {
                continue;
            }
            $parts[] = sanitize_file_name($part);
        }

        return implode('/', $parts);
    }

    /**
     * Return the URL for a plugin-owned frontend JavaScript helper asset.
     *
     * @param string $relative_path Relative path below assets/js/.
     * @return string
     */
    protected function ultracache_frontend_js_asset_url($relative_path)
    {
        $relative_path = $this->ultracache_normalize_frontend_js_asset_path($relative_path);
        if ('' === $relative_path) {
            return '';
        }

        return ultracache_plugin_url('assets/js/' . $relative_path);
    }

    /**
     * Register a plugin-owned frontend JavaScript helper through WordPress APIs.
     *
     * @param string        $handle        Script handle.
     * @param string        $relative_path Relative path below assets/js/.
     * @param array<string> $dependencies  Script dependencies.
     * @param bool          $in_footer     Whether to load in the footer.
     * @return bool
     */
    protected function ultracache_register_frontend_js_helper($handle, $relative_path, $dependencies = array(), $in_footer = false)
    {
        $handle = sanitize_key((string) $handle);

        $runtime_module = method_exists($this, 'ultracache_get_frontend_runtime_module')
            ? $this->ultracache_get_frontend_runtime_module($handle)
            : array();
        if (!empty($runtime_module)) {
            /*
             * 3.12.05: runtime modules no longer register one network asset each.
             * Registration only reserves the module source for its lane bundle;
             * execution is requested separately by ultracache_enqueue_frontend_js_helper().
             */
            return method_exists($this, 'ultracache_include_frontend_runtime_module')
                ? !empty($this->ultracache_include_frontend_runtime_module($handle, false))
                : false;
        }

        $src = $this->ultracache_frontend_js_asset_url($relative_path);
        if ('' === $handle || '' === $src) {
            return false;
        }

        wp_register_script(
            $handle,
            $src,
            is_array($dependencies) ? array_values(array_filter(array_map('sanitize_key', $dependencies))) : array(),
            ULTRACACHE_VERSION,
            array('in_footer' => (bool) $in_footer)
        );

        return true;
    }

    /**
     * Request one plugin-owned frontend helper.
     *
     * 3.12.05 keeps feature/settings ownership at the existing call sites, but
     * runtime modules are collected into one generated asset per execution lane.
     * Their declared external dependencies are still enqueued through WordPress.
     */
    protected function ultracache_enqueue_frontend_js_helper($handle, $relative_path, $dependencies = array(), $in_footer = false)
    {
        $handle = sanitize_key((string) $handle);
        $runtime_module = method_exists($this, 'ultracache_get_frontend_runtime_module')
            ? $this->ultracache_get_frontend_runtime_module($handle)
            : array();

        if (!empty($runtime_module)) {
            $runtime_module = $this->ultracache_request_frontend_runtime_module($handle);
            if (empty($runtime_module)) {
                return false;
            }
            foreach ((array) ($runtime_module['dependencies'] ?? array()) as $dependency) {
                $dependency = sanitize_key((string) $dependency);
                if ('' !== $dependency) {
                    wp_enqueue_script($dependency);
                }
            }
            return true;
        }

        if (!$this->ultracache_register_frontend_js_helper($handle, $relative_path, $dependencies, $in_footer)) {
            return false;
        }

        wp_enqueue_script($handle);
        return true;
    }

    /**
     * Attach sanitized configuration data to a runtime module or standalone helper.
     *
     * Runtime-module configs are held until the lane bundle is finalized so the
     * same globals remain available before the bundled module executes.
     */
    protected function ultracache_add_frontend_js_helper_data($handle, $global_name, array $data)
    {
        $handle = sanitize_key((string) $handle);
        $global_name = preg_replace('/[^A-Za-z0-9_$]/', '', (string) $global_name);

        $runtime_module = method_exists($this, 'ultracache_get_frontend_runtime_module')
            ? $this->ultracache_get_frontend_runtime_module($handle)
            : array();
        if (!empty($runtime_module)) {
            return $this->ultracache_store_frontend_runtime_module_config($handle, $global_name, $data);
        }

        if ('' === $handle || '' === $global_name || !wp_script_is($handle, 'registered')) {
            return false;
        }

        $json = wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json) || '' === $json) {
            return false;
        }

        wp_add_inline_script($handle, 'window.' . $global_name . ' = ' . $json . ';', 'before');
        return true;
    }

    /** Store WordPress script metadata on the eventual lane bundle. */
    protected function ultracache_add_frontend_js_helper_script_data($handle, $key, $value)
    {
        $handle = sanitize_key((string) $handle);
        $runtime_module = method_exists($this, 'ultracache_get_frontend_runtime_module')
            ? $this->ultracache_get_frontend_runtime_module($handle)
            : array();
        if (!empty($runtime_module)) {
            return $this->ultracache_store_frontend_runtime_module_script_data($handle, $key, $value);
        }
        return function_exists('wp_script_add_data') && wp_script_is($handle, 'registered')
            ? (bool) wp_script_add_data($handle, (string) $key, $value)
            : false;
    }

    /**
     * Reserve one lane handle in the WordPress queue at first-module time.
     *
     * The source is intentionally empty until the final module-set is known.
     * Finalization mutates only this UltraCache-owned registration with the
     * content-addressed generated URL, preserving parser/defer queue position.
     */
    private function ultracache_ensure_frontend_runtime_bundle_placeholder($lane)
    {
        $bundle = $this->ultracache_get_frontend_runtime_bundle_by_lane($lane);
        $handle = sanitize_key((string) ($bundle['handle'] ?? ''));
        if ('' === $handle || !function_exists('wp_register_script') || !function_exists('wp_enqueue_script')) {
            return false;
        }
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, '', array(), ULTRACACHE_VERSION, array('in_footer' => false));
        }
        if (!wp_script_is($handle, 'enqueued')) {
            wp_enqueue_script($handle);
        }
        return true;
    }

    /** Return the generated runtime-bundle directory. */
    private function ultracache_frontend_runtime_bundle_dir()
    {
        return function_exists('ultracache_uploads_storage_dir')
            ? trailingslashit(ultracache_uploads_storage_dir('ultracache/js-bundles'))
            : '';
    }

    /** Return the public URL for one generated runtime-bundle file. */
    private function ultracache_frontend_runtime_bundle_url($filename)
    {
        return function_exists('ultracache_uploads_storage_url')
            ? ultracache_uploads_storage_url('ultracache/js-bundles/' . sanitize_file_name((string) $filename))
            : '';
    }

    /** Build the shared activation runtime embedded in every generated lane bundle. */
    private function ultracache_frontend_runtime_bundle_prelude()
    {
        return <<<'JS'
(function(window,document){
'use strict';
var factories=window.__ultracacheRuntimeModuleFactories=window.__ultracacheRuntimeModuleFactories||Object.create(null);
var executed=window.__ultracacheRuntimeModuleExecuted=window.__ultracacheRuntimeModuleExecuted||Object.create(null);
var queue=window.__ultracacheRuntimeModuleActivationQueue=window.__ultracacheRuntimeModuleActivationQueue||[];
if(typeof window.__ultracacheActivateRuntimeModule!=='function'){
window.__ultracacheActivateRuntimeModule=function(id){
id=String(id||'');
if(!id||executed[id]){return true;}
var factory=factories[id];
if(typeof factory!=='function'){
if(queue.indexOf(id)===-1){queue.push(id);}
return false;
}
executed[id]=1;
try{factory();}catch(error){window.setTimeout(function(){throw error;},0);}
return true;
};
}
JS;
    }

    /** Build the activation footer for one generated lane bundle. */
    private function ultracache_frontend_runtime_bundle_postlude()
    {
        return <<<'JS'
if(typeof autoModules!=='undefined'&&Array.isArray(autoModules)){autoModules.forEach(function(id){if(id){window.__ultracacheActivateRuntimeModule(id);}});}
var current=document.currentScript;
var raw=current&&typeof current.getAttribute==='function'?String(current.getAttribute('data-ultracache-modules')||''):'';
if(raw){raw.split(',').forEach(function(id){if(id){window.__ultracacheActivateRuntimeModule(id);}});}
if(queue.length){var pending=queue.slice();queue.length=0;pending.forEach(function(id){window.__ultracacheActivateRuntimeModule(id);});}
}(window,document));
JS;
    }

    /**
     * Build/reuse one content-addressed bundle containing only current-request modules.
     *
     * @param string   $lane       native|defer|delay.
     * @param string[] $module_ids Included registry ids.
     * @return array{path?:string,url?:string,hash?:string,filename?:string}
     */
    private function ultracache_build_frontend_runtime_bundle_asset($lane, array $module_ids)
    {
        $lane = strtolower(trim((string) $lane));
        $module_ids = array_values(array_unique(array_filter(array_map('sanitize_key', $module_ids))));
        sort($module_ids, SORT_STRING);
        if (!in_array($lane, array('native', 'defer', 'delay'), true) || empty($module_ids)) {
            return array();
        }

        $fingerprint = 'runtime-bundle-v1|' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : '') . '|' . $lane . '|' . implode(',', $module_ids);
        $hash = substr(hash('sha256', $fingerprint), 0, 24);
        $filename = 'runtime-' . $lane . '-' . $hash . '.js';
        $dir = $this->ultracache_frontend_runtime_bundle_dir();
        $url = $this->ultracache_frontend_runtime_bundle_url($filename);
        if ('' === $dir || '' === $url) {
            return array();
        }
        $file = $dir . $filename;
        if (is_file($file) && filesize($file) > 0) {
            return array('path' => $file, 'url' => $url, 'hash' => $hash, 'filename' => $filename);
        }

        $definitions = $this->ultracache_frontend_runtime_module_definitions();
        $activation_module_ids = array_values(array_intersect(
            $module_ids,
            $this->ultracache_get_frontend_runtime_auto_module_ids($lane)
        ));
        sort($activation_module_ids, SORT_STRING);

        $payload = $this->ultracache_frontend_runtime_bundle_prelude();
        foreach ($module_ids as $module_id) {
            if (empty($definitions[$module_id]) || $lane !== (string) ($definitions[$module_id]['lane'] ?? '')) {
                return array();
            }
            $asset = sanitize_file_name((string) ($definitions[$module_id]['asset'] ?? ''));
            if ('' === $asset || !defined('ULTRACACHE_PATH')) {
                return array();
            }
            $source_path = trailingslashit(ULTRACACHE_PATH) . 'assets/js/' . $asset;
            $source = function_exists('ultracache_guarded_asset_file_get_contents')
                ? ultracache_guarded_asset_file_get_contents($source_path, 'js', 'runtime_bundle_source_' . $lane, true)
                : false;
            if (!is_string($source) || '' === trim($source)) {
                return array();
            }
            if (function_exists('ultracache_strip_source_mapping_url_comments')) {
                $source = ultracache_strip_source_mapping_url_comments($source);
            }
            $payload .= "\n/* UltraCache runtime module: " . $module_id . " */\n";
            $payload .= 'factories[' . wp_json_encode($module_id) . "]=function(){\n" . $source . "\n};\n";
        }
        $payload .= "\nvar autoModules=" . wp_json_encode($activation_module_ids) . ";\n";
        $payload .= $this->ultracache_frontend_runtime_bundle_postlude();
        if ("\n" !== substr($payload, -1)) {
            $payload .= "\n";
        }

        if (!is_dir($dir) && function_exists('wp_mkdir_p')) {
            wp_mkdir_p($dir);
        }
        if (!is_dir($dir)) {
            return array();
        }
        $index = $dir . 'index.php';
        if (!is_file($index) && function_exists('ultracache_safe_file_put_contents')) {
            ultracache_safe_file_put_contents($index, "<?php\n// Silence is golden.\n", 0, 'runtime_js_bundle_index');
        }
        if (!is_file($file)) {
            $written = function_exists('ultracache_safe_file_put_contents')
                ? ultracache_safe_file_put_contents($file, $payload, LOCK_EX, 'runtime_js_bundle_asset')
                : false;
            if (false === $written || !is_file($file)) {
                return array();
            }
        }
        return array('path' => $file, 'url' => $url, 'hash' => $hash, 'filename' => $filename);
    }

    /** Queue one dependency-bound module activation after its declared dependency. */
    private function ultracache_schedule_frontend_runtime_dependency_activation($module_id, array $module)
    {
        $dependencies = array_values(array_filter(array_map('sanitize_key', (array) ($module['dependencies'] ?? array()))));
        if (empty($dependencies)) {
            return;
        }
        $dependency = (string) end($dependencies);
        if ('' === $dependency || !function_exists('wp_add_inline_script')) {
            return;
        }
        wp_enqueue_script($dependency);
        $code = '(function(w){var i=' . wp_json_encode((string) $module_id) . ';if(typeof w.__ultracacheActivateRuntimeModule==="function"){w.__ultracacheActivateRuntimeModule(i);}else{var q=w.__ultracacheRuntimeModuleActivationQueue=w.__ultracacheRuntimeModuleActivationQueue||[];if(q.indexOf(i)===-1){q.push(i);}}})(window);';
        wp_add_inline_script($dependency, $code, 'after');
    }

    /** Apply all stored module configs/metadata to one finalized bundle handle. */
    private function ultracache_apply_frontend_runtime_bundle_data($lane, $bundle_handle, array $module_ids)
    {
        $definitions = $this->ultracache_frontend_runtime_module_definitions();
        foreach ($module_ids as $module_id) {
            foreach ((array) ($this->ultracache_frontend_runtime_module_configs[$module_id] ?? array()) as $config) {
                $global_name = preg_replace('/[^A-Za-z0-9_$]/', '', (string) ($config['global'] ?? ''));
                $json = wp_json_encode((array) ($config['data'] ?? array()), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                if ('' !== $global_name && is_string($json) && '' !== $json) {
                    wp_add_inline_script($bundle_handle, 'window.' . $global_name . ' = ' . $json . ';', 'before');
                }
            }
            foreach ((array) ($this->ultracache_frontend_runtime_module_script_data[$module_id] ?? array()) as $key => $value) {
                if ('strategy' === (string) $key) {
                    continue; // Lane strategy is owned by the bundle definition.
                }
                if (function_exists('wp_script_add_data')) {
                    wp_script_add_data($bundle_handle, (string) $key, $value);
                }
            }
            if (!empty($this->ultracache_frontend_runtime_requested_modules[$module_id]) && !empty($definitions[$module_id]['dependencies'])) {
                $this->ultracache_schedule_frontend_runtime_dependency_activation($module_id, $definitions[$module_id]);
            }
        }
    }

    /**
     * Persist finalized module metadata on the actual WordPress bundle registration.
     *
     * Generated bundles self-activate from their compile-time requested module set.
     * The external-tag module list remains useful for diagnostics and as a compatibility
     * fallback, but it is no longer the execution authority.
     */
    private function ultracache_store_frontend_runtime_bundle_activation_metadata($lane, $bundle_handle)
    {
        $lane = strtolower(trim((string) $lane));
        $bundle_handle = sanitize_key((string) $bundle_handle);
        $modules = $this->ultracache_get_frontend_runtime_auto_module_ids($lane);

        if ('' === $bundle_handle || !function_exists('wp_scripts')) {
            return $modules;
        }

        $wp_scripts = wp_scripts();
        if (!is_object($wp_scripts) || empty($wp_scripts->registered[$bundle_handle]) || !is_object($wp_scripts->registered[$bundle_handle])) {
            return $modules;
        }

        if (!isset($wp_scripts->registered[$bundle_handle]->extra) || !is_array($wp_scripts->registered[$bundle_handle]->extra)) {
            $wp_scripts->registered[$bundle_handle]->extra = array();
        }
        $wp_scripts->registered[$bundle_handle]->extra['ultracache_runtime_lane'] = $lane;
        $wp_scripts->registered[$bundle_handle]->extra['ultracache_runtime_modules'] = $modules;

        return $modules;
    }

    /** Fallback to the pre-3.12.05 standalone module delivery if bundle generation fails. */
    private function ultracache_enqueue_frontend_runtime_lane_fallback($lane, array $module_ids)
    {
        $bundle = $this->ultracache_get_frontend_runtime_bundle_by_lane($lane);
        $bundle_handle = sanitize_key((string) ($bundle['handle'] ?? ''));
        if ('' !== $bundle_handle && function_exists('wp_dequeue_script')) {
            wp_dequeue_script($bundle_handle);
        }
        $definitions = $this->ultracache_frontend_runtime_module_definitions();
        foreach ($module_ids as $module_id) {
            if (empty($this->ultracache_frontend_runtime_requested_modules[$module_id]) || empty($definitions[$module_id])) {
                continue;
            }
            $module = $definitions[$module_id];
            $handle = (string) ($module['handle'] ?? '');
            $src = $this->ultracache_frontend_js_asset_url((string) ($module['asset'] ?? ''));
            if ('' === $handle || '' === $src) {
                continue;
            }
            wp_register_script($handle, $src, (array) ($module['dependencies'] ?? array()), ULTRACACHE_VERSION, array('in_footer' => !empty($module['in_footer'])));
            foreach ((array) ($this->ultracache_frontend_runtime_module_configs[$module_id] ?? array()) as $config) {
                $global_name = preg_replace('/[^A-Za-z0-9_$]/', '', (string) ($config['global'] ?? ''));
                $json = wp_json_encode((array) ($config['data'] ?? array()), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                if ('' !== $global_name && is_string($json) && '' !== $json) {
                    wp_add_inline_script($handle, 'window.' . $global_name . ' = ' . $json . ';', 'before');
                }
            }
            foreach ((array) ($this->ultracache_frontend_runtime_module_script_data[$module_id] ?? array()) as $key => $value) {
                if (function_exists('wp_script_add_data')) {
                    wp_script_add_data($handle, (string) $key, $value);
                }
            }
            if ('defer' === $lane && function_exists('wp_script_add_data')) {
                $this->ultracache_add_frontend_js_helper_script_data($handle, 'strategy', 'defer');
            }
            wp_enqueue_script($handle);
        }
    }

    /** Return already-finalized predecessor bundle handles for strict lane order. */
    private function ultracache_frontend_runtime_bundle_dependencies($lane)
    {
        $lane = strtolower(trim((string) $lane));
        if ('defer' === $lane && !empty($this->ultracache_frontend_runtime_bundle_assets['native']['handle'])) {
            return array((string) $this->ultracache_frontend_runtime_bundle_assets['native']['handle']);
        }
        if ('delay' === $lane) {
            if (!empty($this->ultracache_frontend_runtime_bundle_assets['defer']['handle'])) {
                return array((string) $this->ultracache_frontend_runtime_bundle_assets['defer']['handle']);
            }
            if (!empty($this->ultracache_frontend_runtime_bundle_assets['native']['handle'])) {
                return array((string) $this->ultracache_frontend_runtime_bundle_assets['native']['handle']);
            }
        }
        return array();
    }

    /**
     * Finalize at most one generated network asset per NATIVE / DEFER / DELAY lane.
     *
     * This runs after feature enqueue callbacks have declared their modules, so
     * no inactive helper source is copied into the current request bundle.
     */
    public function ultracache_finalize_frontend_runtime_bundles()
    {
        if (is_admin() || (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations())) {
            return;
        }
        foreach (array('native', 'defer', 'delay') as $lane) {
            $module_ids = $this->ultracache_get_frontend_runtime_included_module_ids($lane);
            if (empty($module_ids)) {
                continue;
            }
            $bundle = $this->ultracache_get_frontend_runtime_bundle_by_lane($lane);
            $asset = $this->ultracache_build_frontend_runtime_bundle_asset($lane, $module_ids);
            if (empty($bundle['handle']) || empty($asset['url'])) {
                $this->ultracache_enqueue_frontend_runtime_lane_fallback($lane, $module_ids);
                continue;
            }
            $bundle_handle = sanitize_key((string) $bundle['handle']);
            $this->ultracache_ensure_frontend_runtime_bundle_placeholder($lane);
            $wp_scripts = function_exists('wp_scripts') ? wp_scripts() : null;
            if (is_object($wp_scripts) && !empty($wp_scripts->registered[$bundle_handle]) && is_object($wp_scripts->registered[$bundle_handle])) {
                $wp_scripts->registered[$bundle_handle]->src = (string) $asset['url'];
                $wp_scripts->registered[$bundle_handle]->ver = ULTRACACHE_VERSION;
                $wp_scripts->registered[$bundle_handle]->deps = $this->ultracache_frontend_runtime_bundle_dependencies($lane);
            } else {
                wp_register_script(
                    $bundle_handle,
                    (string) $asset['url'],
                    $this->ultracache_frontend_runtime_bundle_dependencies($lane),
                    ULTRACACHE_VERSION,
                    array('in_footer' => false)
                );
                wp_enqueue_script($bundle_handle);
            }
            if ('defer' === $lane && function_exists('wp_script_add_data')) {
                wp_script_add_data($bundle_handle, 'strategy', 'defer');
            }
            $activation_modules = $this->ultracache_store_frontend_runtime_bundle_activation_metadata($lane, $bundle_handle);
            $this->ultracache_apply_frontend_runtime_bundle_data($lane, $bundle_handle, $module_ids);
            wp_enqueue_script($bundle_handle);
            $asset['handle'] = $bundle_handle;
            $asset['modules'] = $module_ids;
            $asset['activationModules'] = $activation_modules;
            $this->ultracache_frontend_runtime_bundle_assets[$lane] = $asset;
        }
    }

    /**
     * Render a zero-network late activation for a module whose factory was reserved.
     * Falls back to its standalone source if the lane bundle could not be built.
     */
    protected function ultracache_render_frontend_runtime_module_activation($handle)
    {
        $module = $this->ultracache_get_frontend_runtime_module($handle);
        if (empty($module['id'])) {
            return '';
        }
        $module_id = (string) $module['id'];
        $lane = (string) ($module['lane'] ?? 'native');
        if (!empty($this->ultracache_frontend_runtime_bundle_assets[$lane])) {
            $code = '(function(w){var i=' . wp_json_encode($module_id) . ';if(typeof w.__ultracacheActivateRuntimeModule==="function"){w.__ultracacheActivateRuntimeModule(i);}else{var q=w.__ultracacheRuntimeModuleActivationQueue=w.__ultracacheRuntimeModuleActivationQueue||[];if(q.indexOf(i)===-1){q.push(i);}}})(window);';
            if (function_exists('wp_get_inline_script_tag')) {
                return wp_get_inline_script_tag($code, array('data-ultracache-runtime-activation' => $module_id));
            }
            return '<script data-ultracache-runtime-activation="' . esc_attr($module_id) . '">' . $code . '</script>';
        }

        $src = $this->ultracache_frontend_js_asset_url((string) ($module['asset'] ?? ''));
        if ('' === $src) {
            return '';
        }
        if (function_exists('wp_get_script_tag')) {
            return wp_get_script_tag(array('src' => $src, 'data-ultracache-runtime-fallback' => $module_id));
        }
        return '<script src="' . esc_url($src) . '" data-ultracache-runtime-fallback="' . esc_attr($module_id) . '"></script>';
    }



    /**
     * Enqueue the external async CSS activation runtime when this request can emit
     * UltraCache-managed non-blocking stylesheet links.
     *
     * @return void
     */
    public function enqueue_async_css_runtime_helper()
    {
        if (is_admin() || (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations())) {
            return;
        }

        $settings = $this->get_settings();
        $critical_chain_delay_enabled = !empty($settings['critical_request_chain_relief'])
            && !empty($settings['critical_request_chain_delay_list'])
            && is_array($settings['critical_request_chain_delay_list']);

        $runtime_required = !empty($settings['async_css'])
            || !empty($settings['async_external_css'])
            || !empty($settings['async_consent_css'])
            || !empty($settings['aggressive_async_css'])
            || !empty($settings['font_mix_css_bundle_async'])
            || !empty($settings['delay_icon_fonts'])
            || $critical_chain_delay_enabled;

        $handle = 'ultracache-async-css-runtime';

        if (!$runtime_required) {
            /*
             * Auto-detect Consent CSS decides only after final HTML rewriting.
             * Reserve the tiny factory now so a later confirmed rewrite can
             * activate it without introducing a fourth/late network request.
             */
            if (!empty($settings['async_consent_css_auto']) && empty($settings['async_consent_css'])) {
                $this->ultracache_reserve_frontend_runtime_module($handle);
            }
            return;
        }

        $this->ultracache_enqueue_frontend_js_helper($handle, 'async-css-runtime.js', array(), false);

    }

    /**
     * Enqueue the runtime JavaScript scan collector through WordPress-native script APIs.
     *
     * The collector is active only for verified runtime-scan requests. It must be
     * printed in the document head before other optimized scripts so it can catch
     * early errors, but it is still loaded through wp_enqueue_scripts,
     * wp_register_script(), wp_enqueue_script(), and wp_add_inline_script().
     *
     * @return void
     */
    /**
     * Enqueue the strict viewport-based third-party iframe activation runtime.
     *
     * @return void
     */
    public function enqueue_lazy_third_party_iframe_runtime_helper()
    {
        if (is_admin() || (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations())) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['lazy_load_third_party_iframes']) || $this->should_skip_lazy_third_party_iframes_for_request()) {
            return;
        }

        $handle = 'ultracache-lazy-third-party-iframes';
        if ($this->ultracache_enqueue_frontend_js_helper(
            $handle,
            'lazy-third-party-iframes.js',
            array(),
            false
        ) && function_exists('wp_script_add_data')) {
            $this->ultracache_add_frontend_js_helper_script_data($handle, 'strategy', 'defer');
        }
    }


    public function enqueue_runtime_js_scan_collector()
    {
        if (is_admin()) {
            return;
        }

        $data = $this->get_runtime_js_scan_request_data();
        if (false === $data || !is_array($data)) {
            return;
        }

        $scan_id = isset($data['scan_id']) ? (string) $data['scan_id'] : '';
        $scan_context = 'anonymous';
        if ('' === $scan_id) {
            return;
        }

        /*
         * Runtime Scan is the diagnostic bootstrap for the JavaScript runtime
         * itself. Keep its collector standalone so scanner availability never
         * depends on runtime-bundle generation, activation metadata, or module
         * factory execution. The registered handle remains known to the central
         * router as a native diagnostic module, so normal JS optimization must
         * not defer or delay it.
         */
        $handle = 'ultracache-runtime-js-scan-collector';
        $src = $this->ultracache_frontend_js_asset_url('runtime-js-scan-collector.js');
        if ('' === $src) {
            return;
        }

        if (wp_script_is($handle, 'registered')) {
            wp_deregister_script($handle);
        }

        wp_register_script(
            $handle,
            $src,
            array(),
            ULTRACACHE_VERSION,
            array('in_footer' => false)
        );

        $json = wp_json_encode(array(
            'scanId'      => $scan_id,
            'scanContext' => $scan_context,
        ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json) || '' === $json) {
            return;
        }

        wp_add_inline_script(
            $handle,
            'window.ultracacheRuntimeJsScanConfig = ' . $json . ';',
            'before'
        );
        wp_enqueue_script($handle);
    }

    /**
     * Enqueue the MailerLite lazy nonce helper with WordPress-native script APIs.
     *
     * This helper intentionally uses wp_enqueue_scripts + wp_register_script(),
     * wp_enqueue_script(), and wp_add_inline_script() only. It does not inject
     * script tags through the HTML output rewrite pipeline.
     *
     * @return void
     */
    public function enqueue_mailerlite_lazy_nonce_helper()
    {
        if (is_admin() || (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations())) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['lazy_mailerlite_nonce'])) {
            return;
        }

        $handle = 'ultracache-mailerlite-lazy-nonce';
        if (!$this->ultracache_enqueue_frontend_js_helper($handle, 'mailerlite-lazy-nonce.js', array(), false)) {
            return;
        }

        $this->ultracache_add_frontend_js_helper_data($handle, 'ultracacheMailerLiteLazyNonceConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ));
    }

    private function should_skip_woocommerce_cart_fragments_empty_cart_control()
    {
        if ((function_exists('is_user_logged_in') && is_user_logged_in()) || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return true;
        }

        foreach (array('is_cart', 'is_checkout', 'is_account_page') as $conditional) {
            if (function_exists($conditional) && call_user_func($conditional)) {
                return true;
            }
        }

        $cookie_names = array();
        if (isset($_COOKIE) && is_array($_COOKIE)) {
            $cookie_names = array_keys(wp_unslash($_COOKIE));
        }

        foreach ($cookie_names as $cookie_name) {
            if ($this->cookie_name_matches_any_pattern($cookie_name, array('woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_'))) {
                return true;
            }
        }

        return false;
    }

    private function should_skip_woocommerce_cart_fragments_delay()
    {
        return $this->should_skip_woocommerce_cart_fragments_empty_cart_control();
    }

    public function filter_woocommerce_cart_fragments_script_data($script_data, $handle)
    {
        if ('wc-cart-fragments' !== (string) $handle) {
            return $script_data;
        }

        if (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations()) {
            return $script_data;
        }

        if ((method_exists($this, 'is_woocommerce_varnish_esi_mini_cart_rendered_for_request')
                && $this->is_woocommerce_varnish_esi_mini_cart_rendered_for_request())
            || (method_exists($this, 'is_woocommerce_litespeed_esi_mini_cart_rendered_for_request')
                && $this->is_woocommerce_litespeed_esi_mini_cart_rendered_for_request())) {
            return $script_data;
        }

        if (is_admin()) {
            return $script_data;
        }

        $settings = $this->get_settings();
        if (empty($settings['woocommerce_cart_fragments_suppress_empty'])) {
            return $script_data;
        }

        if ($this->should_skip_woocommerce_cart_fragments_empty_cart_control()) {
            return $script_data;
        }

        return null;
    }

    private function get_woocommerce_cart_fragments_delay_ms(array $settings)
    {
        $timing = isset($settings['woocommerce_cart_fragments_delay_timing']) ? strtolower(trim((string) $settings['woocommerce_cart_fragments_delay_timing'])) : 'delayed-js';
        if ('delayed-js' === $timing) {
            $seconds = isset($settings['delayed_local_js_auto_start_seconds']) ? (float) $settings['delayed_local_js_auto_start_seconds'] : 0.05;
        } else {
            $seconds = (float) $timing;
        }

        $seconds = max(0.05, min(5.0, $seconds));
        return (int) round(1000 * $seconds);
    }

    private function get_delayed_js_autostart_event_names(array $settings)
    {
        $events = array();
        if (!empty($settings['delayed_js_autostart_mousemove'])) {
            $events[] = 'mousemove';
        }
        if (!empty($settings['delayed_js_autostart_click'])) {
            $events[] = 'click';
        }
        if (!empty($settings['delayed_js_autostart_touch_pointer'])) {
            $events[] = 'touchstart';
            $events[] = 'pointerdown';
        }
        if (!empty($settings['delayed_js_autostart_keyboard'])) {
            $events[] = 'keydown';
        }

        return array_values(array_unique(array_map('sanitize_key', $events)));
    }

    public function enqueue_woocommerce_cart_fragments_delay_helper()
    {
        if (is_admin() || (function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations())) {
            return;
        }

        if (!class_exists('WooCommerce') && !defined('WC_VERSION') && !function_exists('WC')) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['woocommerce_cart_fragments_delay'])
            || $this->should_skip_woocommerce_cart_fragments_delay()
            || (method_exists($this, 'is_woocommerce_varnish_esi_mini_cart_rendered_for_request')
                && $this->is_woocommerce_varnish_esi_mini_cart_rendered_for_request())
            || (method_exists($this, 'is_woocommerce_litespeed_esi_mini_cart_rendered_for_request')
                && $this->is_woocommerce_litespeed_esi_mini_cart_rendered_for_request())) {
            return;
        }

        $handle = 'ultracache-woocommerce-cart-fragments-delay';
        if (!$this->ultracache_enqueue_frontend_js_helper($handle, 'woocommerce-cart-fragments-delay.js', array('jquery'), false)) {
            return;
        }

        $woocommerce_delay_timing = isset($settings['woocommerce_cart_fragments_delay_timing']) ? strtolower(trim((string) $settings['woocommerce_cart_fragments_delay_timing'])) : 'delayed-js';
        $woocommerce_auto_timer_enabled = !('delayed-js' === $woocommerce_delay_timing && 'infinite' === (string) ($settings['delayed_local_js_auto_start'] ?? 'custom'));

        $this->ultracache_add_frontend_js_helper_data($handle, 'ultracacheWooCartFragmentsDelayConfig', array(
            'autoEvents'       => $this->get_delayed_js_autostart_event_names($settings),
            'autoAfterLoad'    => !empty($settings['delayed_js_autostart_after_load']),
            'autoTimerEnabled' => $woocommerce_auto_timer_enabled,
            'autoDelayMs'      => $this->get_woocommerce_cart_fragments_delay_ms($settings),
            'skipCartCookies'  => true,
        ));
    }

}
