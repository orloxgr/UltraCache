<?php
/**
 * Core procedural helper compatibility loader for UltraCache.
 *
 * These files are loaded by ultracache.php before the main plugin class and
 * before the engine/REST/WP-CLI components. Existing global function names and
 * load behavior remain unchanged.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/integrations/wpml/functions.php';
require_once dirname(__DIR__) . '/integrations/translatepress/functions.php';
require_once dirname(__DIR__) . '/integrations/multilingual/functions.php';
require_once plugin_dir_path(__FILE__) . 'paths.php';
require_once plugin_dir_path(__FILE__) . 'signing.php';
require_once plugin_dir_path(__FILE__) . 'query-policy.php';
require_once plugin_dir_path(__FILE__) . 'request-policy.php';
require_once plugin_dir_path(__FILE__) . 'storage.php';
require_once plugin_dir_path(__FILE__) . 'media-variant-paths.php';
require_once plugin_dir_path(__FILE__) . 'debug.php';
require_once plugin_dir_path(__FILE__) . 'request-profiler.php';
require_once plugin_dir_path(__FILE__) . 'locks.php';
require_once plugin_dir_path(__FILE__) . 'coordination-state.php';
require_once plugin_dir_path(__FILE__) . 'filesystem-guards.php';
require_once plugin_dir_path(__FILE__) . 'http-guards.php';
require_once plugin_dir_path(__FILE__) . 'varnish.php';
