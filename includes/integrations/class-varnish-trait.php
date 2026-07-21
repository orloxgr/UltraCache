<?php
/**
 * Varnish and reverse-proxy integration facade for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-metrics-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-settings-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-capability-fingerprint-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-endpoint-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-transport-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-url-normalization-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-batch-invalidation-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-queue-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-soft-purge-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-stale-refresh-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-refresh-candidates-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-refresh-ahead-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-origin-refill-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-origin-revalidation-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-refill-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-flush-scope-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-invalidation-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-diagnostics-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-test-action-trait.php');

trait Ultra_Cache_WP_Varnish_Trait
{
    use Ultra_Cache_WP_Varnish_Metrics_Trait;
    use Ultra_Cache_WP_Varnish_Settings_Trait;
    use Ultra_Cache_WP_Varnish_Capability_Fingerprint_Trait;
    use Ultra_Cache_WP_Varnish_Endpoint_Trait;
    use Ultra_Cache_WP_Varnish_Transport_Trait;
    use Ultra_Cache_WP_Varnish_URL_Normalization_Trait;
    use Ultra_Cache_WP_Varnish_Batch_Invalidation_Trait;
    use Ultra_Cache_WP_Varnish_Queue_Trait;
    use Ultra_Cache_WP_Varnish_Soft_Purge_Trait;
    use Ultra_Cache_WP_Varnish_Stale_Refresh_Trait;
    use Ultra_Cache_WP_Varnish_Refresh_Candidates_Trait;
    use Ultra_Cache_WP_Varnish_Refresh_Ahead_Trait;
    use Ultra_Cache_WP_Varnish_Origin_Refill_Trait;
    use Ultra_Cache_WP_Varnish_Origin_Revalidation_Trait;
    use Ultra_Cache_WP_Varnish_Refill_Trait;
    use Ultra_Cache_WP_Varnish_Flush_Scope_Trait;
    use Ultra_Cache_WP_Varnish_Invalidation_Trait;
    use Ultra_Cache_WP_Varnish_Diagnostics_Trait;
    use Ultra_Cache_WP_Varnish_Test_Action_Trait;
}
