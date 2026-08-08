<?php
/**
 * Media converter for UltraCache.
 */

defined('ABSPATH') || exit;

require_once ultracache_plugin_dir('includes/media/class-media-diagnostics-trait.php');
require_once ultracache_plugin_dir('includes/media/class-media-avif-self-test-trait.php');
require_once ultracache_plugin_dir('includes/media/class-media-background-queue-trait.php');
require_once ultracache_plugin_dir('includes/media/class-media-queue-trait.php');
require_once ultracache_plugin_dir('includes/media/class-media-conversion-trait.php');
require_once ultracache_plugin_dir('includes/media/class-media-replacement-facade-trait.php');
require_once ultracache_plugin_dir('includes/media/class-media-path-url-trait.php');
require_once ultracache_plugin_dir('includes/media/class-media-html-rewrite-trait.php');


final class Ultra_Cache_Media_Converter {

	use Ultra_Cache_Media_Diagnostics_Trait;
	use Ultra_Cache_Media_Avif_Self_Test_Trait;
	use Ultra_Cache_Media_Background_Queue_Trait;
	use Ultra_Cache_Media_Queue_Trait;
	use Ultra_Cache_Media_Conversion_Trait;
	use Ultra_Cache_Media_Replacement_Facade_Trait;
	use Ultra_Cache_Media_Path_Url_Trait;
	use Ultra_Cache_Media_Html_Rewrite_Trait;

	/**
	 * Singleton instance.
	 *
	 * @var Ultra_Cache_Media_Converter|null
	 */
	private static $instance = null;

	/**
	 * Whether final HTML rewrite buffering has started.
	 *
	 * @var bool
	 */
	private $final_buffering = false;

	/**
	 * Per-request memoized optimized variant existence checks.
	 *
	 * @var array<string,bool>
	 */
	private $optimized_variant_exists_memo = array();

	/**
	 * Per-request source/output freshness states for generated media variants.
	 *
	 * @var array<string,string>
	 */
	private $optimized_variant_freshness_memo = array();

	/**
	 * Per-request source file fingerprints used by freshness and queue dedupe.
	 *
	 * @var array<string,array<string,int|bool>>
	 */
	private $optimized_source_fingerprint_memo = array();


	/**
	 * Per-request canonical source descriptors for local public image URLs.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $local_public_image_source_memo = array();

	/**
	 * Per-request manifest-style lookup cache for local public image URLs.
	 *
	 * Keeps media rewrite lookup-only paths from repeating URL parsing and
	 * filesystem existence checks for the same generated image size.
	 *
	 * @var array<string,string|false>
	 */
	private $optimized_public_url_lookup_memo = array();

	/**
	 * Per-request memo from upload source file paths to attachment IDs for
	 * lightweight on-demand queue synchronization.
	 *
	 * @var array<string,int>
	 */
	private $on_demand_source_attachment_memo = array();

	/**
	 * Per-request missing-media queue discovery keys.
	 *
	 * @var array<string,bool>
	 */
	private $on_demand_queue_discovery_seen = array();

	/**
	 * Number of missing media items queued from lookup-only rewrites.
	 *
	 * @var int
	 */
	private $on_demand_queue_discovery_count = 0;

	/**
	 * Per-request page/media relation keys already recorded for affected-page purge.
	 *
	 * @var array<string,bool>
	 */
	private $on_demand_affected_page_seen = array();

	/**
	 * Current safe generation context: frontend, warm, cron, stale, or manual.
	 *
	 * @var string
	 */
	private $media_generation_context = 'frontend';

	/**
	 * Explicit public page URL for media discovery during cache-storage rewrites.
	 *
	 * Warm, cron, CLI, and stale workers rewrite HTML outside the original
	 * frontend request, so REQUEST_URI cannot identify the page that must be
	 * purged after a missing optimized image is generated.
	 *
	 * @var string
	 */
	private $media_rewrite_page_url_context = '';

	/**
	 * Last public page whose final HTML was scanned for missing media.
	 *
	 * One cron/CLI worker can process many pages in the same PHP request. Keep
	 * the discovery budget and de-duplication scope per page rather than letting
	 * the first warmed pages consume the budget for every later page.
	 *
	 * @var string
	 */
	private $media_rewrite_discovery_page_context = '';

	/**
	 * Explicit Accept header context for full-document media rewrites.
	 *
	 * Warm/CLI storage writes run in a parent PHP process that does not inherit
	 * the loopback request's HTTP_ACCEPT header. Keep the selected image bucket
	 * explicit and scoped so final AVIF/WebP reconciliation uses the same
	 * context as the warmed cache file without mutating global $_SERVER state.
	 *
	 * @var string|null
	 */
	private $media_rewrite_accept_context = null;

	/**
	 * Bounded request-local exact media-type support memo by Accept context.
	 *
	 * @var array<string,bool>
	 */
	private $media_accept_support_memo = array();

	/**
	 * Background conversion cron hook.
	 */
	const BACKGROUND_QUEUE_HOOK = 'ultracache_process_media_conversion_queue';


	/**
	 * Missing-media on-demand queue dedupe lock prefix.
	 */
	const MEDIA_ON_DEMAND_QUEUE_LOCK_PREFIX = 'ultracache_media_odq_';

	/** Persistent media encoder capability state. */
	const MEDIA_SUPPORT_STATE = 'ultracache_state:media.encoder_capabilities';

	/** Persistent media library work-summary state. */
	const MEDIA_WORK_SUMMARY_STATE = 'ultracache_state:media.work_summary';

	/** Persistent optimized-media storage-health state prefix. */
	const MEDIA_STORAGE_HEALTH_STATE_PREFIX = 'ultracache_state:media.storage_health.';

	/** Persistent GD WebP behavior-probe state. */
	const GD_WEBP_PROBE_STATE = 'ultracache_state:media.gd_webp_probe';

	/** Persistent optimized-media file counters. */
	const MEDIA_FILE_COUNTS_OPTION = 'ultracache_media_file_counts';

	/**
	 * Stores the most recent media conversion diagnostics.
	 */
	const MEDIA_DIAGNOSTICS_OPTION = 'ultracache_media_diagnostics_v1';

	/** Stores the most recent Media Library conversion test report. */
	const MEDIA_LIBRARY_CONVERSION_TEST_OPTION = 'ultracache_media_library_conversion_test_v1';

	/** Legacy transient key used before the report became an explicit option. */

	/** Stores the fixed Media Library attachment sample used by repeated conversion tests. */
	const MEDIA_LIBRARY_CONVERSION_TEST_SAMPLE_OPTION = 'ultracache_media_library_conversion_test_sample_v1';

	/** Persistent AVIF encoder self-test state. */
	const AVIF_SELF_TEST_STATE = 'ultracache_state:media.avif_self_test';

	/** Superseded AVIF encoder self-test option removed during migration. */
	const AVIF_SELF_TEST_LEGACY_OPTION = 'ultracache_avif_encoder_self_test_v1';

	/** AVIF encoder self-test algorithm version. */
	const AVIF_SELF_TEST_VERSION = 4;

	/** Persistent Media Library replacement table version. */
	const MEDIA_REPLACEMENT_DB_VERSION = '14';

	/** Media Library replacement orchestration generation. Legacy jobs are intentionally not migrated. */
	const MEDIA_REPLACEMENT_ORCHESTRATION_VERSION = 7;

	/** Short-lived lock protecting readiness inventory cursor and counters from concurrent chunks. */
	const MEDIA_REPLACEMENT_READINESS_LOCK = 'ultracache_media_replacement_readiness_lock_v1';

	/** Lifetime of a destructive Media Replacement start-confirmation token. */
	const MEDIA_REPLACEMENT_CONFIRMATION_TTL = 600;

	/** Token-owned dashboard lease for resumable Media Library replacement jobs. */
	const MEDIA_REPLACEMENT_MANUAL_SESSION_LOCK = 'ultracache_media_replacement_manual_session_v1';

	/** Dashboard replacement lease lifetime. Each successful chunk renews it. */
	const MEDIA_REPLACEMENT_MANUAL_SESSION_TTL = 120;

	/** Backward-compatible name for the shared destructive confirmation TTL. */
	const MEDIA_REPLACEMENT_DELETE_CONFIRMATION_TTL = self::MEDIA_REPLACEMENT_CONFIRMATION_TTL;

	/** Persistent Media Library replacement database version option. */
	const MEDIA_REPLACEMENT_DB_VERSION_OPTION = 'ultracache_media_replacement_db_version';

	/** Shared database lock serializing Media Library replacement schema upgrades. */
	const MEDIA_REPLACEMENT_SCHEMA_LOCK = 'ultracache_media_replacement_schema_lock_v1';

	/** Maximum lifetime of a Media Library replacement schema-upgrade lock. */
	const MEDIA_REPLACEMENT_SCHEMA_LOCK_TTL = 60;

	/** Persistent media conversion queue table version. */
	const MEDIA_QUEUE_DB_VERSION = '6';

	/** Persistent media conversion queue database version option. */
	const MEDIA_QUEUE_DB_VERSION_OPTION = 'ultracache_media_queue_db_version';

	/** Persistent physical media conversion unit table version. */
	const MEDIA_QUEUE_UNITS_DB_VERSION = '2';

	/** Persistent physical media conversion unit database version option. */
	const MEDIA_QUEUE_UNITS_DB_VERSION_OPTION = 'ultracache_media_queue_units_db_version';

	/** Persistent bounded physical media unit migration cursor. */
	const MEDIA_QUEUE_UNITS_MIGRATION_STATE_OPTION = 'ultracache_media_queue_units_migration_state_v1';

	/** Persistent media conversion queue rebuild cursor option. */
	const MEDIA_QUEUE_BUILD_STATE_OPTION = 'ultracache_media_queue_build_state_v1';

	/** Authoritative media queue rebuild generation intent. */
	const MEDIA_QUEUE_REBUILD_GENERATION_OPTION = 'ultracache_media_queue_rebuild_generation_v1';

	/** Persistent on-demand media affected page refs table version. */
	const MEDIA_PAGE_REFS_DB_VERSION = '3';

	/** Persistent on-demand media affected page refs database version option. */
	const MEDIA_PAGE_REFS_DB_VERSION_OPTION = 'ultracache_media_page_refs_db_version';

	/** Shared token-owned media conversion processor lock. */
	const MEDIA_QUEUE_PROCESS_LOCK = 'ultracache_media_queue_process_lock_v1';

	/** Dedicated token-owned media queue rebuild lock. */
	const MEDIA_QUEUE_REBUILD_LOCK = 'ultracache_media_queue_rebuild_lock_v1';

	/** Single dispatcher lock for immediate and cron background media workers. */
	const MEDIA_BACKGROUND_DISPATCH_LOCK = 'ultracache_media_background_dispatch_lock_v1';

	/** Exclusive dashboard media-conversion session lock. */
	const MEDIA_MANUAL_CONVERSION_LOCK = 'ultracache_media_manual_conversion_lock_v1';

	/** Persistent administrator pause for all media generation work. */
	const MEDIA_BACKGROUND_PAUSED_OPTION = 'ultracache_media_background_paused_v1';

	/** Rolling stale-worker incidents and temporary cooldown state. */
	const MEDIA_STALE_WORKER_STATE_OPTION = 'ultracache_media_stale_worker_state_v1';

	/** Seconds before a processing media queue item is considered stale. */
	const MEDIA_QUEUE_PROCESSING_TTL = 300;


	/**
	 * Get singleton instance.
	 *
	 * @return Ultra_Cache_Media_Converter
	 */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}


	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter('wp_unique_filename', array($this, 'filter_cross_extension_unique_image_filename'), 20, 6);
		add_filter('wp_handle_upload', array($this, 'maybe_convert_uploaded_image_file'), 20, 2);
		add_filter('wp_generate_attachment_metadata', array($this, 'maybe_generate_avif_on_upload'), 20, 2);
		add_action('delete_attachment', array($this, 'delete_avif_by_attachment_id'));
		add_action(self::BACKGROUND_QUEUE_HOOK, array($this, 'process_background_generation_queue'));
		add_action('init', array($this, 'maybe_schedule_pending_background_generation'), 20);
		add_filter('wp_get_attachment_image_attributes', array($this, 'filter_attachment_image_attributes'), 20, 3);
		add_filter('wp_calculate_image_srcset', array($this, 'filter_attachment_image_srcset'), 20, 5);
		add_filter('the_content', array($this, 'rewrite_filtered_content_image_urls'), 999);
		add_filter('post_thumbnail_html', array($this, 'rewrite_filtered_content_image_urls'), 999);
		add_filter('widget_text_content', array($this, 'rewrite_filtered_content_image_urls'), 999);
		add_filter('render_block', array($this, 'rewrite_filtered_content_image_urls'), 999);
		add_action('template_redirect', array($this, 'maybe_start_final_html_buffer'), 999);
	}

}
