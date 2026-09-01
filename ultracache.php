<?php
/**
 * Plugin Name: UltraCache - Cache and Speed Optimization
 * Plugin URI: https://github.com/orloxgr/ultracache
 * Description: WordPress page cache, object cache, media optimization, Varnish purge tools, warm-up, and performance diagnostics.
 * Version: 3.13.12
 * Author: Byron Iniotakis
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ultracache
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ULTRACACHE_VERSION')) {
    define('ULTRACACHE_VERSION', '3.13.12');
}
if (!defined('ULTRACACHE_FILE')) {
    define('ULTRACACHE_FILE', __FILE__);
}
if (!defined('ULTRACACHE_BASENAME')) {
    define('ULTRACACHE_BASENAME', plugin_basename(__FILE__));
}
if (!defined('ULTRACACHE_PATH')) {
    define('ULTRACACHE_PATH', plugin_dir_path(__FILE__));
}
if (!defined('ULTRACACHE_URL')) {
    define('ULTRACACHE_URL', plugin_dir_url(__FILE__));
}

require_once ULTRACACHE_PATH . 'includes/core/functions.php';
require_once ULTRACACHE_PATH . 'includes/core/html-variant-functions.php';
require_once ULTRACACHE_PATH . 'includes/core/srcset-functions.php';
require_once ULTRACACHE_PATH . 'includes/integrations/varnish/esi/functions.php';
require_once ULTRACACHE_PATH . 'includes/integrations/litespeed/esi/functions.php';
require_once ULTRACACHE_PATH . 'includes/integrations/woocommerce/functions.php';
require_once ULTRACACHE_PATH . 'includes/bootstrap/class-class-loader.php';
Ultra_Cache_Class_Loader::register();
if (!defined('ULTRACACHE_SETTINGS_KEY')) {
    define('ULTRACACHE_SETTINGS_KEY', 'ultracache_settings');
}
if (!defined('ULTRACACHE_CRON_WARM_STATE_KEY')) {
    define('ULTRACACHE_CRON_WARM_STATE_KEY', 'ultracache_cron_warm_state');
}
if (!defined('ULTRACACHE_CRON_WARM_LOCK_KEY')) {
    define('ULTRACACHE_CRON_WARM_LOCK_KEY', 'ultracache_cron_warm_lock');
}
if (!defined('ULTRACACHE_MANUAL_WARM_STATE_KEY')) {
    define('ULTRACACHE_MANUAL_WARM_STATE_KEY', 'ultracache_manual_warm_state');
}
if (!defined('ULTRACACHE_CRAWL_SCOPE_SUMMARY_KEY')) {
    define('ULTRACACHE_CRAWL_SCOPE_SUMMARY_KEY', 'ultracache_crawl_scope_summary');
}
if (!defined('ULTRACACHE_WP_CACHE_MANAGED_KEY')) {
    define('ULTRACACHE_WP_CACHE_MANAGED_KEY', 'ultracache_wp_cache_managed');
}
if (!defined('ULTRACACHE_CACHE_DIR')) {
    define('ULTRACACHE_CACHE_DIR', ultracache_content_cache_storage_dir());
}
if (!defined('ULTRACACHE_OPTIMIZED_IMAGES_DIR')) {
    define('ULTRACACHE_OPTIMIZED_IMAGES_DIR', ultracache_optimized_images_storage_dir());
}
if (!defined('ULTRACACHE_OPTIMIZED_IMAGES_URL')) {
    define('ULTRACACHE_OPTIMIZED_IMAGES_URL', ultracache_optimized_images_storage_url_path());
}
if (!defined('ULTRACACHE_AVIF_DIR')) {
    define('ULTRACACHE_AVIF_DIR', ultracache_optimized_images_storage_dir('avif'));
}
if (!defined('ULTRACACHE_AVIF_URL')) {
    define('ULTRACACHE_AVIF_URL', ultracache_optimized_images_storage_url_path('avif'));
}
if (!defined('ULTRACACHE_WEBP_DIR')) {
    define('ULTRACACHE_WEBP_DIR', ultracache_optimized_images_storage_dir('webp'));
}
if (!defined('ULTRACACHE_WEBP_URL')) {
    define('ULTRACACHE_WEBP_URL', ultracache_optimized_images_storage_url_path('webp'));
}
if (!defined('ULTRACACHE_OBJECT_CACHE_DIR')) {
    define('ULTRACACHE_OBJECT_CACHE_DIR', ultracache_object_cache_storage_dir());
}


require_once ultracache_plugin_dir('includes/fonts/functions.php');
require_once ultracache_plugin_dir('includes/settings/class-settings-trait.php');
require_once ultracache_plugin_dir('includes/settings/class-settings-persistence-trait.php');
require_once ultracache_plugin_dir('includes/admin/class-admin-trait.php');
require_once ultracache_plugin_dir('includes/integrations/class-varnish-trait.php');
require_once ultracache_plugin_dir('includes/integrations/elementor/class-elementor-cache-coherency-trait.php');
require_once ultracache_plugin_dir('includes/integrations/real-cookie-banner/class-real-cookie-banner-compatibility-trait.php');
require_once ultracache_plugin_dir('includes/integrations/complianz/class-complianz-compatibility-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/esi/class-varnish-esi-endpoint-trait.php');
require_once ultracache_plugin_dir('includes/integrations/litespeed/esi/class-litespeed-esi-endpoint-trait.php');
require_once ultracache_plugin_dir('includes/integrations/varnish/esi/class-varnish-esi-lifecycle-trait.php');
require_once ultracache_plugin_dir('includes/diagnostics/class-diagnostics-trait.php');
require_once ultracache_plugin_dir('includes/bootstrap/class-bootstrap-trait.php');
require_once ultracache_plugin_dir('includes/cache/class-dropin-reconciliation-trait.php');
require_once ultracache_plugin_dir('includes/runtime/class-runtime-config-trait.php');
require_once ultracache_plugin_dir('includes/integrations/wpml/class-wpml-topology-trait.php');
require_once ultracache_plugin_dir('includes/integrations/multilingual/class-multilingual-topology-trait.php');
require_once ultracache_plugin_dir('includes/integrations/litespeed/class-litespeed-metrics-trait.php');
require_once ultracache_plugin_dir('includes/integrations/litespeed/class-litespeed-transport-trait.php');
require_once ultracache_plugin_dir('includes/integrations/litespeed/class-litespeed-control-trait.php');
require_once ultracache_plugin_dir('includes/integrations/litespeed/class-litespeed-queue-trait.php');
require_once ultracache_plugin_dir('includes/integrations/litespeed/class-litespeed-refill-trait.php');
require_once ultracache_plugin_dir('includes/integrations/litespeed/class-litespeed-diagnostics-trait.php');
require_once ultracache_plugin_dir('includes/runtime/class-runtime-cache-services-trait.php');
require_once ultracache_plugin_dir('includes/setup/class-setup-planning-trait.php');
require_once ultracache_plugin_dir('includes/setup/class-setup-apache-static-capability-trait.php');
require_once ultracache_plugin_dir('includes/setup/class-setup-wizard-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-warm-plan-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-warm-rate-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-warm-coordination-access-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-warm-decision-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-warm-status-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-cron-warm-orchestrator-trait.php');
require_once ultracache_plugin_dir('includes/warmup/class-targeted-warm-pipeline-trait.php');
require_once ultracache_plugin_dir('includes/maintenance/class-scheduled-maintenance-trait.php');
require_once ultracache_plugin_dir('includes/maintenance/class-update-cache-invalidation-trait.php');
require_once ultracache_plugin_dir('includes/storage/class-cache-asset-registry-trait.php');
require_once ultracache_plugin_dir('includes/server/class-server-rules-trait.php');
require_once ultracache_plugin_dir('includes/server/class-wp-config-manager-trait.php');
require_once ultracache_plugin_dir('includes/lifecycle/class-plugin-data-cleanup-trait.php');

class Ultra_Cache_WP
{
    use Ultra_Cache_WP_Bootstrap_Trait;
    use Ultra_Cache_WP_Dropin_Reconciliation_Trait;
    use Ultra_Cache_WP_Runtime_Config_Trait;
    use Ultra_Cache_WP_Multilingual_Topology_Trait;
    use Ultra_Cache_WP_LiteSpeed_Metrics_Trait;
    use Ultra_Cache_WP_LiteSpeed_Transport_Trait;
    use Ultra_Cache_WP_LiteSpeed_Control_Trait;
    use Ultra_Cache_WP_LiteSpeed_Queue_Trait;
    use Ultra_Cache_WP_LiteSpeed_Refill_Trait;
    use Ultra_Cache_WP_LiteSpeed_Diagnostics_Trait;
    use Ultra_Cache_WP_Runtime_Cache_Services_Trait;
    use Ultra_Cache_WP_Setup_Planning_Trait;
    use Ultra_Cache_WP_Setup_Apache_Static_Capability_Trait;
    use Ultra_Cache_WP_Setup_Wizard_Trait;
    use Ultra_Cache_WP_Warm_Plan_Trait;
    use Ultra_Cache_WP_Warm_Rate_Trait;
    use Ultra_Cache_WP_Warm_Coordination_Access_Trait;
    use Ultra_Cache_WP_Warm_Decision_Trait;
    use Ultra_Cache_WP_Warm_Status_Trait;
    use Ultra_Cache_WP_Cron_Warm_Orchestrator_Trait;
    use Ultra_Cache_WP_Targeted_Warm_Pipeline_Trait;
    use Ultra_Cache_WP_Scheduled_Maintenance_Trait;
    use Ultra_Cache_WP_Update_Cache_Invalidation_Trait;
    use Ultra_Cache_WP_Cache_Asset_Registry_Trait;
    use Ultra_Cache_WP_Server_Rules_Trait;
    use Ultra_Cache_WP_Config_Manager_Trait;
    use Ultra_Cache_WP_Plugin_Data_Cleanup_Trait;
    use Ultra_Cache_WP_Settings_Trait;
    use Ultra_Cache_WP_Settings_Persistence_Trait;
    use Ultra_Cache_WP_Admin_Trait;
    use Ultra_Cache_WP_Varnish_Trait;
    use Ultra_Cache_WP_Elementor_Cache_Coherency_Trait;
    use Ultra_Cache_WP_Real_Cookie_Banner_Compatibility_Trait;
    use Ultra_Cache_WP_Complianz_Compatibility_Trait;
    use Ultra_Cache_WP_Varnish_ESI_Endpoint_Trait;
    use Ultra_Cache_WP_LiteSpeed_ESI_Endpoint_Trait;
    use Ultra_Cache_WP_Varnish_ESI_Lifecycle_Trait;
    use Ultra_Cache_WP_Diagnostics_Trait;

    /** @var Ultra_Cache_WP|null */
    private static $instance = null;

    /** @var array|null */
    private static $dashboard_settings_cache = null;

    /** @var array|null */
    private static $settings_cache = null;
    /** @var bool Temporarily suppress automatic cron warm starts while scheduled cleanup purges cache. */
    private static $suppress_after_purge_warm = false;

    /** @var bool Current request owns the disposable warm-runtime upgrade reset. */
    private static $public_warm_runtime_reset_active = false;

    /** @var bool Coalesced WooCommerce routing-contract synchronization is pending. */
    private static $woocommerce_endpoint_contract_sync_pending = false;

    /** @var bool A late WPML public-topology comparison is pending. */
    private static $wpml_topology_reconciliation_pending = false;

    /** @var bool WPML topology reconciliation is currently mutating cache state. */
    private static $wpml_topology_reconciliation_running = false;

    /** @var string Source that requested the late WPML topology comparison. */
    private static $wpml_topology_reconciliation_source = '';

    /** @var bool A late provider-neutral multilingual topology comparison is pending. */
    private static $multilingual_topology_reconciliation_pending = false;

    /** @var bool Provider-neutral multilingual topology reconciliation is mutating cache state. */
    private static $multilingual_topology_reconciliation_running = false;

    /** @var string Source that requested the late multilingual topology comparison. */
    private static $multilingual_topology_reconciliation_source = '';
}

function ultracache_ultracache()
{
    return Ultra_Cache_WP::instance();
}

register_activation_hook(__FILE__, array('Ultra_Cache_WP', 'activate'));
register_deactivation_hook(__FILE__, array('Ultra_Cache_WP', 'deactivate'));
ultracache_ultracache();
