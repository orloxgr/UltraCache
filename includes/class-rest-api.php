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
require_once __DIR__ . '/rest/class-rest-lcp-diagnostics-trait.php';
require_once __DIR__ . '/rest/class-rest-helpers-trait.php';

class Ultra_Cache_Rest_API
{
    use Ultra_Cache_Rest_Schemas_Trait;
    use Ultra_Cache_Rest_Routes_Trait;
    use Ultra_Cache_Rest_Cache_Trait;
    use Ultra_Cache_Rest_Media_Trait;
    use Ultra_Cache_Rest_Action_Queue_Trait;
    use Ultra_Cache_Rest_Profiler_Trait;
    use Ultra_Cache_Rest_LCP_Diagnostics_Trait;
    use Ultra_Cache_Rest_Helpers_Trait;

    /** @var Ultra_Cache_Rest_API|null */
    private static $instance = null;

    /** @var bool */
    private static $routes_registered = false;

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
        add_filter('rest_post_dispatch', array($this, 'add_no_cache_headers_to_ultracache_rest_response'), 10, 3);
    }

    public function add_no_cache_headers_to_ultracache_rest_response($response, $server, $request)
    {
        unset($server);

        if (!$request instanceof WP_REST_Request) {
            return $response;
        }

        $route = (string) $request->get_route();
        if (0 !== strpos($route, '/ultracache/v1/')) {
            return $response;
        }

        if (!defined('DONOTCACHEPAGE')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress cache plugins use DONOTCACHEPAGE as the standard no-cache signal.
            define('DONOTCACHEPAGE', true);
        }

        if ($response instanceof WP_REST_Response || $response instanceof WP_HTTP_Response) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT');
            $response->header('X-Accel-Expires', '0');
            $response->header('Surrogate-Control', 'no-store');
            $response->header('CDN-Cache-Control', 'no-store');
            $response->header('X-LiteSpeed-Cache-Control', 'no-cache');
            $response->header('X-UltraCache-Admin-No-Cache', '1');
        }

        return $response;
    }

    public function register_routes()
    {
        if (self::$routes_registered) {
            return;
        }
        self::$routes_registered = true;

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

