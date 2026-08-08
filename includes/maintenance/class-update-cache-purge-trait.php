<?php
/**
 * Engine-level page and generated frontend asset purge after successful updates.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Update_Cache_Purge_Trait
{
    /**
     * Purge page HTML and generated frontend assets after a successful update.
     *
     * Object-cache and optimized-media directories are intentionally untouched.
     *
     * @param array $context Update context for diagnostics.
     * @return bool
     */
    public function purge_frontend_cache_after_update(array $context = array())
    {
        $lock_name = 'purge-after-update';
        if (!$this->acquire_runtime_lock($lock_name, 180)) {
            return false;
        }

        try {
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'reset_cron_warmup_queue_after_cache_flush')) {
                $queue_reset = Ultra_Cache_WP::reset_cron_warmup_queue_after_cache_flush('update_purge');
                if (is_array($queue_reset) && empty($queue_reset['queueResetSuccess'])) {
                    return false;
                }
            }

            $this->purge_cache_directory_preserving_google_fonts();
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'mark_all_cache_asset_refs_inactive')) {
                Ultra_Cache_WP::mark_all_cache_asset_refs_inactive();
            }

            // Remove manifests/maps so new frontend output is rebuilt, while
            // retaining old hashed assets for stale browser/proxy HTML until
            // normal generated-asset cleanup ages them out.
            $this->delete_frontpage_css_bundle();
            $this->clear_runtime_font_css_map_cache();
            self::ensure_cache_directories();

            $this->invalidate_dashboard_cache_activity_snapshot();
            $payload = array(
                'scope'       => 'update',
                'update_type' => sanitize_key((string) ($context['update_type'] ?? '')),
                'bulk'        => !empty($context['bulk']),
                'items'       => array_slice(array_values(array_filter((array) ($context['items'] ?? array()), 'is_scalar')), 0, 50),
            );
            $this->record_cache_event('purge-after-update', $payload);
            $this->record_analytics_purge('update');
            do_action('ultracache_after_update_purge', $payload);
            return true;
        } finally {
            $this->release_runtime_lock($lock_name, true);
        }
    }

}
