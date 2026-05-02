<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/rest/class-rest-schemas-trait.php';
require_once __DIR__ . '/rest/class-rest-routes-trait.php';
require_once __DIR__ . '/rest/class-rest-cache-trait.php';
require_once __DIR__ . '/rest/class-rest-media-trait.php';
require_once __DIR__ . '/rest/class-rest-action-queue-trait.php';
require_once __DIR__ . '/rest/class-rest-profiler-trait.php';
require_once __DIR__ . '/rest/class-rest-helpers-trait.php';

if (!class_exists('Ultra_Cache_Rest_API')) {
    class Ultra_Cache_Rest_API
    {
        use Ultra_Cache_Rest_Schemas_Trait;
        use Ultra_Cache_Rest_Routes_Trait;
        use Ultra_Cache_Rest_Cache_Trait;
        use Ultra_Cache_Rest_Media_Trait;
        use Ultra_Cache_Rest_Action_Queue_Trait;
        use Ultra_Cache_Rest_Profiler_Trait;
        use Ultra_Cache_Rest_Helpers_Trait;

        /** @var Ultra_Cache_Rest_API|null */
        private static $instance = null;

        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        private function __construct()
        {
            add_action('rest_api_init', array($this, 'register_routes'));
        }

        public function register_routes()
        {
            $definitions = $this->get_route_definitions();
            $canonical_namespace = $this->get_canonical_namespace();
            foreach ($definitions as $route => $handlers) {
                register_rest_route($canonical_namespace, $route, $handlers);
            }
        }

        private function get_canonical_namespace()
        {
            return 'ultracache/v1';
        }

    }
}

if (!class_exists('Ultra_Cache_REST_API') && class_exists('Ultra_Cache_Rest_API')) {
    class_alias('Ultra_Cache_Rest_API', 'Ultra_Cache_REST_API');
}
