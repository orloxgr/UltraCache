<?php
/**
 * Media converter for UltraCache.
 */

defined('ABSPATH') || exit;

require_once UCWP_PATH . 'includes/media/class-media-diagnostics-trait.php';
require_once UCWP_PATH . 'includes/media/class-media-background-queue-trait.php';
require_once UCWP_PATH . 'includes/media/class-media-queue-trait.php';
require_once UCWP_PATH . 'includes/media/class-media-conversion-trait.php';
require_once UCWP_PATH . 'includes/media/class-media-path-url-trait.php';
require_once UCWP_PATH . 'includes/media/class-media-html-rewrite-trait.php';

if (!class_exists('Ultra_Cache_Media_Converter')) {

	final class Ultra_Cache_Media_Converter {

		use Ultra_Cache_Media_Diagnostics_Trait;
		use Ultra_Cache_Media_Background_Queue_Trait;
		use Ultra_Cache_Media_Queue_Trait;
		use Ultra_Cache_Media_Conversion_Trait;
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
		 * Number of frontend on-demand conversions started during this request.
		 *
		 * @var int
		 */
		private $on_demand_conversions_started = 0;

		/**
		 * Request start timestamp used for frontend on-demand conversion budgeting.
		 *
		 * @var float|null
		 */
		private $on_demand_request_started_at = null;


		/**
		 * Per-request memoized optimized variant existence checks.
		 *
		 * @var array<string,bool>
		 */
		private $optimized_variant_exists_memo = array();

		/**
		 * Per-request map from discovered upload image URL tokens to optimized URLs.
		 *
		 * @var array<string,string>
		 */
		private $optimized_image_url_rewrite_map = array();

		/**
		 * Current safe generation context: frontend, warm, cron, stale, or manual.
		 *
		 * @var string
		 */
		private $media_generation_context = 'frontend';

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
		 * Background conversion queue option name.
		 */
		const BACKGROUND_QUEUE_OPTION = 'ucwp_media_conversion_queue';

		/**
		 * Background conversion cron hook.
		 */
		const BACKGROUND_QUEUE_HOOK = 'ucwp_process_media_conversion_queue';

		/**
		 * Background conversion queue lock transient.
		 */
		const BACKGROUND_QUEUE_LOCK = 'ucwp_media_conversion_queue_lock';

		/**
		 * Cached media work summary transient.
		 */
		const MEDIA_WORK_SUMMARY_TRANSIENT = 'ucwp_media_work_summary_v1';

		/**
		 * Stores the most recent media conversion diagnostics.
		 */
		const MEDIA_DIAGNOSTICS_OPTION = 'ucwp_media_diagnostics_v1';

		/** Persistent media conversion queue table version. */
		const MEDIA_QUEUE_DB_VERSION = '1';

		/** Persistent media conversion queue database version option. */
		const MEDIA_QUEUE_DB_VERSION_OPTION = 'ucwp_media_queue_db_version';

		/** Persistent media conversion queue rebuild cursor option. */
		const MEDIA_QUEUE_BUILD_STATE_OPTION = 'ucwp_media_queue_build_state_v1';

		/** Persistent media conversion queue lock transient. */
		const MEDIA_QUEUE_PROCESS_LOCK = 'ucwp_media_queue_process_lock_v1';

		/** Seconds before a processing media queue item is considered stale. */
		const MEDIA_QUEUE_PROCESSING_TTL = 600;


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
			add_filter('wp_generate_attachment_metadata', array($this, 'maybe_generate_avif_on_upload'), 20, 2);
			add_action('delete_attachment', array($this, 'delete_avif_by_attachment_id'));
			add_action(self::BACKGROUND_QUEUE_HOOK, array($this, 'process_background_generation_queue'));
			add_action('init', array($this, 'maybe_schedule_pending_background_generation'), 20);
			add_filter('wp_get_attachment_image_attributes', array($this, 'filter_attachment_image_attributes'), 20, 3);
			add_filter('wp_calculate_image_srcset', array($this, 'filter_attachment_image_srcset'), 20, 5);
			add_filter('the_content', array($this, 'rewrite_html_image_urls'), 999);
			add_filter('post_thumbnail_html', array($this, 'rewrite_html_image_urls'), 999);
			add_filter('widget_text_content', array($this, 'rewrite_html_image_urls'), 999);
			add_filter('render_block', array($this, 'rewrite_html_image_urls'), 999);
			add_action('template_redirect', array($this, 'maybe_start_final_html_buffer'), 999);
		}

	}
}
