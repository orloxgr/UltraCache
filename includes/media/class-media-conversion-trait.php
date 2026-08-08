<?php
/**
 * UltraCache media conversion compatibility aggregator.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once ultracache_plugin_dir('includes/media/conversion/class-media-source-admission-trait.php');
require_once ultracache_plugin_dir('includes/media/conversion/class-media-source-animation-trait.php');
require_once ultracache_plugin_dir('includes/media/conversion/class-media-source-orientation-trait.php');
require_once ultracache_plugin_dir('includes/media/conversion/class-media-source-color-profile-trait.php');
require_once ultracache_plugin_dir('includes/media/conversion/class-media-output-commit-trait.php');
require_once ultracache_plugin_dir('includes/media/conversion/class-media-encoder-trait.php');
require_once ultracache_plugin_dir('includes/media/conversion/class-media-conversion-test-trait.php');
require_once ultracache_plugin_dir('includes/media/conversion/class-media-upload-conversion-trait.php');

trait Ultra_Cache_Media_Conversion_Trait
{
    use Ultra_Cache_Media_Source_Admission_Trait;
    use Ultra_Cache_Media_Source_Animation_Trait;
    use Ultra_Cache_Media_Source_Orientation_Trait;
    use Ultra_Cache_Media_Source_Color_Profile_Trait;
    use Ultra_Cache_Media_Output_Commit_Trait;
    use Ultra_Cache_Media_Encoder_Trait;
    use Ultra_Cache_Media_Conversion_Test_Trait;
    use Ultra_Cache_Media_Upload_Conversion_Trait;
}
