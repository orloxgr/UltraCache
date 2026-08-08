<?php
/**
 * UltraCache Media Library replacement foundation for UltraCache media converter.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-schema-trait.php');
require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-session-trait.php');
require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-readiness-trait.php');
require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-blockers-trait.php');
require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-registry-trait.php');
require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-files-trait.php');
require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-metadata-trait.php');
require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-database-trait.php');
require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-theme-css-trait.php');
require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-theme-css-stream-trait.php');
require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-workflow-trait.php');
require_once ultracache_plugin_dir('includes/media/replacement/class-media-replacement-cleanup-trait.php');

trait Ultra_Cache_Media_Replacement_Trait
{
    use Ultra_Cache_Media_Replacement_Schema_Trait;
    use Ultra_Cache_Media_Replacement_Session_Trait;
    use Ultra_Cache_Media_Replacement_Readiness_Trait;
    use Ultra_Cache_Media_Replacement_Blockers_Trait;
    use Ultra_Cache_Media_Replacement_Registry_Trait;
    use Ultra_Cache_Media_Replacement_Files_Trait;
    use Ultra_Cache_Media_Replacement_Metadata_Trait;
    use Ultra_Cache_Media_Replacement_Database_Trait;
    use Ultra_Cache_Media_Replacement_Theme_CSS_Trait, Ultra_Cache_Media_Replacement_Theme_CSS_Stream_Trait {
        Ultra_Cache_Media_Replacement_Theme_CSS_Stream_Trait::scan_media_library_replacement_theme_css_references insteadof Ultra_Cache_Media_Replacement_Theme_CSS_Trait;
        Ultra_Cache_Media_Replacement_Theme_CSS_Stream_Trait::apply_media_library_replacement_theme_css_replacements insteadof Ultra_Cache_Media_Replacement_Theme_CSS_Trait;
        Ultra_Cache_Media_Replacement_Theme_CSS_Stream_Trait::verify_media_library_replacement_theme_css_replacements insteadof Ultra_Cache_Media_Replacement_Theme_CSS_Trait;
    }
    use Ultra_Cache_Media_Replacement_Workflow_Trait;
    use Ultra_Cache_Media_Replacement_Cleanup_Trait;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom Media Library replacement registry tables with validated table identifiers.

    public function prepare_media_library_replacement_foundation($args = array())
    {
        $result = $this->scan_media_library_replacement_eligible_items($args);
        $status = $this->get_media_replacement_table_status();

        $result['itemsTable'] = $status['itemsTable'];
        $result['refsTable']  = $status['refsTable'];

        return $result;
    }
}
