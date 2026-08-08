<?php
/**
 * Elementor generated-file and element-output cache coherency integration.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Elementor_Cache_Coherency_Trait
{
    /** @var bool Elementor cleared generated public files during this request. */
    private static $elementor_files_cache_clear_pending = false;

    /** @var bool Shutdown reconciliation callback registered for this request. */
    private static $elementor_files_cache_clear_shutdown_registered = false;

    /** @var bool The current Elementor clear was initiated by UltraCache Flush All. */
    private static $elementor_cache_clear_owned_by_ultracache = false;

    /** @var array<string,mixed>|null Request-local Elementor Flush All result. */
    private static $elementor_flush_all_result = null;

    /**
     * Resolve the active Elementor files manager without changing Elementor state.
     *
     * @return object|null
     */
    private static function get_elementor_files_manager()
    {
        if (!class_exists('Elementor\\Plugin') || !method_exists('Elementor\\Plugin', 'instance')) {
            return null;
        }

        try {
            $plugin = \Elementor\Plugin::instance();
        } catch (Throwable $throwable) {
            return null;
        }

        if (!is_object($plugin) || !isset($plugin->files_manager) || !is_object($plugin->files_manager)) {
            return null;
        }

        return method_exists($plugin->files_manager, 'clear_cache') ? $plugin->files_manager : null;
    }

    /**
     * Report whether the active Elementor installation exposes its native cache
     * clear manager.
     *
     * @return array<string,mixed>
     */
    public static function get_elementor_cache_status()
    {
        $detected = defined('ELEMENTOR_VERSION') || class_exists('Elementor\\Plugin');
        $files_manager = $detected ? self::get_elementor_files_manager() : null;
        $flushable = is_object($files_manager);
        $post_css_class = 'Elementor\\Core\\Files\\CSS\\Post';
        $page_css_warm_supported = $detected
            && class_exists($post_css_class)
            && method_exists($post_css_class, 'create');

        return array(
            'label' => __('Elementor Cache', 'ultracache'),
            'detected' => (bool) $detected,
            'flushable' => (bool) $flushable,
            'enabled' => (bool) $detected,
            'method' => $flushable ? 'Elementor\\Plugin::instance()->files_manager->clear_cache' : 'unavailable',
            'pageCssWarmSupported' => (bool) $page_css_warm_supported,
            'pageCssWarmMethod' => $page_css_warm_supported
                ? 'Elementor\\Core\\Files\\CSS\\Post::create($post_id)->update'
                : 'unavailable',
            'message' => $flushable
                ? ($page_css_warm_supported
                    ? __('Elementor native cache clear and per-page generated CSS warm reconciliation are available.', 'ultracache')
                    : __('Elementor native generated-files and element-output cache clear is available, but per-page generated CSS warm reconciliation is unavailable.', 'ultracache'))
                : ($detected
                    ? __('Elementor is active, but its native cache clear manager is unavailable in this request.', 'ultracache')
                    : __('Elementor was not detected.', 'ultracache')),
        );
    }

    /**
     * Clear Elementor through its native files manager while preventing the
     * resulting Elementor action from scheduling a second UltraCache purge.
     *
     * @return array<string,mixed>
     */
    public static function flush_elementor_cache()
    {
        $files_manager = self::get_elementor_files_manager();
        if (!is_object($files_manager)) {
            return array(
                'success' => false,
                'message' => __('Elementor native cache clear is unavailable.', 'ultracache'),
            );
        }

        self::$elementor_cache_clear_owned_by_ultracache = true;
        try {
            $files_manager->clear_cache();
            return array(
                'success' => true,
                'message' => __('Elementor cache and generated files were cleared successfully.', 'ultracache'),
                'method' => 'Elementor\\Plugin::instance()->files_manager->clear_cache',
            );
        } catch (Throwable $throwable) {
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: Elementor cache clear error message. */
                    __('Elementor cache clear failed: %s', 'ultracache'),
                    sanitize_text_field($throwable->getMessage())
                ),
            );
        } finally {
            self::$elementor_cache_clear_owned_by_ultracache = false;
        }
    }

    /**
     * Run the optional Elementor native clear before UltraCache removes its own
     * page cache. This order ensures any configured warm-after-flush work starts
     * only after Elementor has finished deleting its old generated files.
     *
     * Elementor-originated invalidation already follows a native Elementor clear,
     * so it must not clear Elementor a second time.
     *
     * @param array<string,mixed> $context Flush All context.
     * @return array<string,mixed>
     */
    public static function maybe_flush_elementor_cache_before_purge(array $context = array())
    {
        $source = sanitize_key((string) ($context['source'] ?? ''));
        $settings = self::get_dashboard_settings();

        if ('elementor' === $source) {
            self::$elementor_flush_all_result = array(
                'success' => true,
                'handled' => true,
                'message' => __('Handled by the Elementor-originated cache clear that requested this Flush All.', 'ultracache'),
            );
            return self::$elementor_flush_all_result;
        }

        if (empty($settings['flushAllIncludeElementor'])) {
            self::$elementor_flush_all_result = array(
                'success' => true,
                'skipped' => true,
                'message' => __('Skipped by setting.', 'ultracache'),
            );
            return self::$elementor_flush_all_result;
        }

        $status = self::get_elementor_cache_status();
        if (empty($status['flushable'])) {
            self::$elementor_flush_all_result = array(
                'success' => true,
                'skipped' => true,
                'message' => __('Elementor was not detected or its native cache clear is unavailable.', 'ultracache'),
            );
            return self::$elementor_flush_all_result;
        }

        self::$elementor_flush_all_result = self::flush_elementor_cache();
        return self::$elementor_flush_all_result;
    }

    /**
     * Return the Elementor result recorded by the current Flush All request.
     *
     * @return array<string,mixed>|null
     */
    public static function get_elementor_flush_all_result()
    {
        return is_array(self::$elementor_flush_all_result) ? self::$elementor_flush_all_result : null;
    }

    /**
     * Queue one end-of-request UltraCache Flush All after Elementor removes its
     * public generated files.
     *
     * Elementor can clear the same generated-file set more than once during a
     * single request. Flushing immediately on the first callback can therefore
     * publish fresh cached HTML before a later Elementor clear removes the CSS
     * files again. The callback only records the invalidation; one canonical
     * Flush All runs at shutdown after every Elementor clear in that request.
     *
     * @return void
     */
    public function handle_elementor_files_cache_clear()
    {
        if (self::$elementor_cache_clear_owned_by_ultracache) {
            return;
        }

        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        self::$elementor_files_cache_clear_pending = true;

        if (self::$elementor_files_cache_clear_shutdown_registered) {
            return;
        }

        self::$elementor_files_cache_clear_shutdown_registered = true;
        add_action('shutdown', array($this, 'flush_after_elementor_files_cache_clear'), PHP_INT_MAX);
    }

    /**
     * Reconcile every UltraCache page-cache layer after the final Elementor
     * generated-file clear performed in the current request.
     *
     * @return void
     */
    public function flush_after_elementor_files_cache_clear()
    {
        if (!self::$elementor_files_cache_clear_pending) {
            return;
        }

        self::$elementor_files_cache_clear_pending = false;

        if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
            return;
        }

        $engine = Ultra_Cache_Engine::get_instance();
        if (!$engine || !method_exists($engine, 'purge_all')) {
            return;
        }

        $engine->purge_all(array(
            'reason' => 'elementor_files_cache_clear',
            'source' => 'elementor',
        ));
    }
}
