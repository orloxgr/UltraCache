<?php
/**
 * WPML public URL-topology change reconciliation.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_WPML_Topology_Trait
{
    /**
     * Mark this request for a late public-API topology comparison.
     *
     * WPML emits wpml_update_active_languages while changing active languages,
     * but other supported settings (URL mode/domain/default language) may be
     * written later in admin/AJAX flows without one common post-save action.
     * The marker therefore never snapshots immediately; shutdown observes the
     * final public topology after the writer has completed.
     *
     * @param mixed $old_active_languages Optional WPML action payload.
     * @return void
     */
    public static function mark_wpml_topology_reconciliation_pending($old_active_languages = null)
    {
        unset($old_active_languages);
        self::$wpml_topology_reconciliation_pending = true;
        self::$wpml_topology_reconciliation_source = 'wpml-active-languages';
    }

    /**
     * Compare already-persisted topology once normal WordPress bootstrap is ready.
     *
     * @return void
     */
    public static function maybe_reconcile_wpml_topology_on_wp_loaded()
    {
        self::maybe_reconcile_wpml_topology('wp-loaded');
    }

    /**
     * Compare again late on requests that can write WPML topology during runtime.
     *
     * @return void
     */
    public static function maybe_reconcile_wpml_topology_on_shutdown()
    {
        if (self::$wpml_topology_reconciliation_running) {
            return;
        }

        $should_compare = self::$wpml_topology_reconciliation_pending;
        if (!$should_compare && function_exists('is_admin') && is_admin()) {
            $should_compare = true;
        }
        if (!$should_compare && function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            $should_compare = true;
        }
        if (!$should_compare && function_exists('wp_doing_cron') && wp_doing_cron()) {
            $should_compare = true;
        }
        if (!$should_compare && defined('REST_REQUEST') && REST_REQUEST) {
            $should_compare = true;
        }
        if (!$should_compare && defined('WP_CLI') && WP_CLI) {
            $should_compare = true;
        }

        if (!$should_compare) {
            return;
        }

        $source = '' !== self::$wpml_topology_reconciliation_source
            ? self::$wpml_topology_reconciliation_source
            : 'late-runtime';
        self::maybe_reconcile_wpml_topology($source);
    }

    /**
     * Observe, stage, invalidate, and apply one WPML topology contract.
     *
     * First observation is a baseline and intentionally does not purge. A real
     * later change is staged before cache mutation; appliedFingerprint remains
     * on the last completed topology until local cache purge and early page-cache
     * bootstrap regeneration both complete. A failed purge is therefore retried
     * by the next normal comparison without an independent execution deadline.
     *
     * @param string $source Observation source.
     * @return bool
     */
    public static function maybe_reconcile_wpml_topology($source = 'runtime')
    {
        if (self::$wpml_topology_reconciliation_running || !function_exists('ultracache_wpml_get_topology_snapshot')) {
            return false;
        }

        $snapshot = ultracache_wpml_get_topology_snapshot();
        if (!is_array($snapshot) || empty($snapshot['ready']) || empty($snapshot['fingerprint'])) {
            return false;
        }

        self::$wpml_topology_reconciliation_running = true;
        try {
            $option_key = function_exists('ultracache_wpml_topology_option_key')
                ? ultracache_wpml_topology_option_key()
                : 'ultracache_wpml_topology_v1';
            $stored = get_option($option_key, array());
            $stored = is_array($stored) ? $stored : array();

            $fingerprint = (string) $snapshot['fingerprint'];
            $observed_fingerprint = (string) ($stored['observedFingerprint'] ?? '');
            $applied_fingerprint = (string) ($stored['appliedFingerprint'] ?? '');
            $now = time();
            $source = sanitize_key((string) $source);
            if ('' === $source) {
                $source = 'runtime';
            }

            // Establish the current topology as the first known baseline. An
            // upgrade/install must not flush a healthy cache merely because the
            // topology observer did not exist in an earlier UltraCache version.
            if ('' === $observed_fingerprint && '' === $applied_fingerprint) {
                update_option($option_key, array(
                    'schemaVersion'       => 1,
                    'observedFingerprint' => $fingerprint,
                    'observedSnapshot'    => $snapshot,
                    'appliedFingerprint'  => $fingerprint,
                    'appliedSnapshot'     => $snapshot,
                    'observedAt'          => $now,
                    'appliedAt'           => $now,
                    'lastChangeAt'        => 0,
                    'lastChangeSource'    => 'baseline',
                ), false);
                self::$wpml_topology_reconciliation_pending = false;
                self::$wpml_topology_reconciliation_source = '';
                return true;
            }

            if (
                '' !== $observed_fingerprint
                && hash_equals($observed_fingerprint, $fingerprint)
                && '' !== $applied_fingerprint
                && hash_equals($applied_fingerprint, $fingerprint)
            ) {
                self::$wpml_topology_reconciliation_pending = false;
                self::$wpml_topology_reconciliation_source = '';
                return true;
            }

            // A previously staged topology may have reverted before it could be
            // applied. If the live public topology already equals the last
            // successfully applied one, reconcile the observation only; there is
            // no cache boundary to cross again.
            if ('' !== $applied_fingerprint && hash_equals($applied_fingerprint, $fingerprint)) {
                $stored['schemaVersion'] = 1;
                $stored['observedFingerprint'] = $fingerprint;
                $stored['observedSnapshot'] = $snapshot;
                $stored['observedAt'] = $now;
                update_option($option_key, $stored, false);
                self::$wpml_topology_reconciliation_pending = false;
                self::$wpml_topology_reconciliation_source = '';
                return true;
            }

            // Persist observation before mutation. Keep the old applied contract
            // until mutation completes so any interruption remains retryable.
            $staged = $stored;
            $staged['schemaVersion'] = 1;
            $staged['observedFingerprint'] = $fingerprint;
            $staged['observedSnapshot'] = $snapshot;
            $staged['observedAt'] = $now;
            $staged['lastChangeAt'] = $now;
            $staged['lastChangeSource'] = $source;
            if (!isset($staged['appliedFingerprint'])) {
                $staged['appliedFingerprint'] = $applied_fingerprint;
            }
            update_option($option_key, $staged, false);

            $engine = method_exists(static::class, 'get_engine_instance') ? self::get_engine_instance() : null;
            if (!$engine || !method_exists($engine, 'purge_all')) {
                return false;
            }

            $previous_suppression = self::$suppress_after_purge_warm;
            self::$suppress_after_purge_warm = true;
            try {
                $purged = $engine->purge_all(array(
                    'reason' => 'wpml_topology_change',
                    'source' => 'wpml-topology',
                ));
                if (!$purged) {
                    return false;
                }

                $settings = self::get_dashboard_settings();
                $page_cache_enabled = !empty($settings['pageCacheEnabled']);
                $page_cache_sync = self::sync_page_cache_bootstrap($page_cache_enabled, false);
                if (function_exists('is_wp_error') && is_wp_error($page_cache_sync)) {
                    return false;
                }
                if (false === $page_cache_sync) {
                    return false;
                }

                if (defined('ULTRACACHE_CRAWL_SCOPE_SUMMARY_KEY')) {
                    delete_option(ULTRACACHE_CRAWL_SCOPE_SUMMARY_KEY);
                } else {
                    delete_option('ultracache_crawl_scope_summary');
                }
            } finally {
                self::$suppress_after_purge_warm = $previous_suppression;
            }

            $applied = $staged;
            $applied['appliedFingerprint'] = $fingerprint;
            $applied['appliedSnapshot'] = $snapshot;
            $applied['appliedAt'] = time();
            update_option($option_key, $applied, false);

            self::$wpml_topology_reconciliation_pending = false;
            self::$wpml_topology_reconciliation_source = '';

            if (!$previous_suppression && method_exists(static::class, 'maybe_start_cron_warmup_after_purge')) {
                self::maybe_start_cron_warmup_after_purge('manual_purge', false);
            }

            return true;
        } finally {
            self::$wpml_topology_reconciliation_running = false;
        }
    }
}
