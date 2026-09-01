<?php
/**
 * Provider-neutral multilingual public URL-topology reconciliation.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Multilingual_Topology_Trait
{
    /**
     * Mark one request for a late provider-neutral topology comparison.
     *
     * @param string $source Observation source.
     * @return void
     */
    public static function mark_multilingual_topology_reconciliation_pending($source = 'runtime')
    {
        self::$multilingual_topology_reconciliation_pending = true;
        $source = sanitize_key((string) $source);
        self::$multilingual_topology_reconciliation_source = '' !== $source ? $source : 'runtime';
    }

    /**
     * WPML public action adapter.
     *
     * @param mixed $old_active_languages WPML action payload.
     * @return void
     */
    public static function mark_wpml_topology_reconciliation_pending($old_active_languages = null)
    {
        unset($old_active_languages);
        self::mark_multilingual_topology_reconciliation_pending('wpml-active-languages');
    }

    /**
     * TranslatePress trp_settings option-update adapter.
     *
     * The TranslatePress settings component can retain its pre-save in-memory
     * value for the rest of the writer request. We therefore mark the request
     * and compare late, while the unconditional next-request wp_loaded compare
     * remains the authoritative fallback if the component cache is still old at
     * shutdown.
     *
     * @param mixed  $old_value Previous option value.
     * @param mixed  $value     New option value.
     * @param string $option    Option name.
     * @return void
     */
    public static function mark_translatepress_topology_reconciliation_pending($old_value = null, $value = null, $option = 'trp_settings')
    {
        unset($old_value, $value, $option);
        self::mark_multilingual_topology_reconciliation_pending('translatepress-settings');
    }

    /**
     * Compare persisted topology once normal WordPress bootstrap is ready.
     *
     * @return void
     */
    public static function maybe_reconcile_multilingual_topology_on_wp_loaded()
    {
        if (
            function_exists('ultracache_multilingual_get_provider')
            && 'wpml' === ultracache_multilingual_get_provider()
            && (!function_exists('did_action') || did_action('wp') < 1)
        ) {
            return;
        }

        if (function_exists('ultracache_multilingual_reconcile_warm_policy_repository')) {
            ultracache_multilingual_reconcile_warm_policy_repository();
        }
        self::maybe_reconcile_multilingual_topology('wp-loaded');
    }

    /**
     * Reconcile WPML only after its documented active-language lifecycle point.
     *
     * @return void
     */
    public static function maybe_reconcile_multilingual_topology_on_wp()
    {
        if (!function_exists('ultracache_multilingual_get_provider') || 'wpml' !== ultracache_multilingual_get_provider()) {
            return;
        }

        if (function_exists('ultracache_multilingual_reconcile_warm_policy_repository')) {
            ultracache_multilingual_reconcile_warm_policy_repository();
        }
        self::maybe_reconcile_multilingual_topology('wp');
    }

    /**
     * Compare again late on requests that can mutate multilingual topology.
     *
     * @return void
     */
    public static function maybe_reconcile_multilingual_topology_on_shutdown()
    {
        if (self::$multilingual_topology_reconciliation_running) {
            return;
        }

        if (
            function_exists('ultracache_multilingual_get_provider')
            && 'wpml' === ultracache_multilingual_get_provider()
            && (!function_exists('did_action') || did_action('wp') < 1)
        ) {
            return;
        }

        if (function_exists('ultracache_multilingual_reconcile_warm_policy_repository')) {
            ultracache_multilingual_reconcile_warm_policy_repository();
        }

        $should_compare = self::$multilingual_topology_reconciliation_pending;
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

        $source = '' !== self::$multilingual_topology_reconciliation_source
            ? self::$multilingual_topology_reconciliation_source
            : 'late-runtime';
        self::maybe_reconcile_multilingual_topology($source);
    }

    /**
     * Observe, stage, invalidate, and apply one provider-qualified topology.
     *
     * First generic observation is always a no-purge baseline. This is the
     * migration boundary from ultracache_wpml_topology_v1 and intentionally does
     * not delete or reinterpret the legacy state. Later real topology changes
     * keep the last applied fingerprint until the canonical cache mutation
     * boundary completes, so interrupted work remains retryable.
     *
     * @param string $source Observation source.
     * @return bool
     */
    public static function maybe_reconcile_multilingual_topology($source = 'runtime')
    {
        if (self::$multilingual_topology_reconciliation_running
            || !function_exists('ultracache_multilingual_get_topology_snapshot')
        ) {
            return false;
        }

        $snapshot = ultracache_multilingual_get_topology_snapshot();
        if (!is_array($snapshot) || empty($snapshot['ready']) || empty($snapshot['fingerprint'])) {
            return false;
        }

        self::$multilingual_topology_reconciliation_running = true;
        try {
            $option_key = function_exists('ultracache_multilingual_topology_option_key')
                ? ultracache_multilingual_topology_option_key()
                : 'ultracache_multilingual_topology_v1';
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

            if ('' === $observed_fingerprint && '' === $applied_fingerprint) {
                $legacy_wpml_state = get_option('ultracache_wpml_topology_v1', array());
                $migration_source = is_array($legacy_wpml_state) && !empty($legacy_wpml_state)
                    ? 'wpml-v1-baseline'
                    : 'baseline';

                update_option($option_key, array(
                    'schemaVersion'       => 1,
                    'observedFingerprint' => $fingerprint,
                    'observedSnapshot'    => $snapshot,
                    'appliedFingerprint'  => $fingerprint,
                    'appliedSnapshot'     => $snapshot,
                    'observedAt'          => $now,
                    'appliedAt'           => $now,
                    'lastChangeAt'        => 0,
                    'lastChangeSource'    => $migration_source,
                ), false);
                self::$multilingual_topology_reconciliation_pending = false;
                self::$multilingual_topology_reconciliation_source = '';
                return true;
            }

            if (
                '' !== $observed_fingerprint
                && hash_equals($observed_fingerprint, $fingerprint)
                && '' !== $applied_fingerprint
                && hash_equals($applied_fingerprint, $fingerprint)
            ) {
                self::$multilingual_topology_reconciliation_pending = false;
                self::$multilingual_topology_reconciliation_source = '';
                return true;
            }

            if ('' !== $applied_fingerprint && hash_equals($applied_fingerprint, $fingerprint)) {
                $stored['schemaVersion'] = 1;
                $stored['observedFingerprint'] = $fingerprint;
                $stored['observedSnapshot'] = $snapshot;
                $stored['observedAt'] = $now;
                update_option($option_key, $stored, false);
                self::$multilingual_topology_reconciliation_pending = false;
                self::$multilingual_topology_reconciliation_source = '';
                return true;
            }

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

            $previous_applied_snapshot = isset($stored['appliedSnapshot']) && is_array($stored['appliedSnapshot'])
                ? $stored['appliedSnapshot']
                : array();
            $needs_page_cache_bootstrap_sync = function_exists('ultracache_multilingual_topology_requires_page_cache_bootstrap_sync')
                ? ultracache_multilingual_topology_requires_page_cache_bootstrap_sync($previous_applied_snapshot, $snapshot)
                : true;

            $previous_suppression = self::$suppress_after_purge_warm;
            self::$suppress_after_purge_warm = true;
            try {
                $purged = $engine->purge_all(array(
                    'reason'   => 'multilingual_topology_change',
                    'source'   => 'multilingual-topology',
                    'provider' => sanitize_key((string) ($snapshot['provider'] ?? 'none')),
                ));
                if (!$purged) {
                    return false;
                }

                if ($needs_page_cache_bootstrap_sync) {
                    $settings = self::get_dashboard_settings();
                    $page_cache_enabled = !empty($settings['pageCacheEnabled']);
                    $page_cache_sync = self::sync_page_cache_bootstrap($page_cache_enabled, false);
                    if (function_exists('is_wp_error') && is_wp_error($page_cache_sync)) {
                        return false;
                    }
                    if (false === $page_cache_sync) {
                        return false;
                    }
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

            self::$multilingual_topology_reconciliation_pending = false;
            self::$multilingual_topology_reconciliation_source = '';

            if (!$previous_suppression && method_exists(static::class, 'maybe_start_cron_warmup_after_purge')) {
                self::maybe_start_cron_warmup_after_purge('manual_purge', false);
            }

            return true;
        } finally {
            self::$multilingual_topology_reconciliation_running = false;
        }
    }
}
