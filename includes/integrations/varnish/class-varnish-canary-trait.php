<?php
/**
 * Varnish capability canary storage and public response helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Canary_Trait
{
    /**
     * Return the public query variable used by the WordPress-served canary.
     *
     * @return string
     */
    private static function get_varnish_canary_query_var()
    {
        return 'ultracache_varnish_canary';
    }

    /**
     * Register the bounded public canary query variable.
     *
     * @param array $query_vars Existing public query variables.
     * @return array
     */
    public function register_varnish_canary_query_var($query_vars)
    {
        $query_vars = is_array($query_vars) ? $query_vars : array();
        $query_vars[] = self::get_varnish_canary_query_var();

        return array_values(array_unique($query_vars));
    }

    /**
     * Return the dedicated Varnish test directory.
     *
     * @return string
     */
    protected static function get_varnish_canary_directory()
    {
        if (!function_exists('ultracache_uploads_storage_dir')) {
            return '';
        }

        return trailingslashit(ultracache_uploads_storage_dir('ultracache/varnishtest'));
    }

    /**
     * Return the direct public URL for one canary file.
     *
     * @param string $filename Canary filename.
     * @return string
     */
    protected static function get_varnish_canary_direct_url($filename)
    {
        if (!function_exists('ultracache_uploads_storage_url')) {
            return '';
        }

        $filename = sanitize_file_name((string) $filename);
        if ('' === $filename) {
            return '';
        }

        return esc_url_raw(ultracache_uploads_storage_url('ultracache/varnishtest/' . $filename));
    }

    /**
     * Return the WordPress-served public URL for one canary identifier.
     *
     * @param string $identifier Canary identifier.
     * @return string
     */
    protected static function get_varnish_canary_application_url($identifier)
    {
        $identifier = self::sanitize_varnish_canary_identifier($identifier);
        if ('' === $identifier) {
            return '';
        }

        return esc_url_raw(home_url('/ultracache-varnishtest/' . $identifier . '/'));
    }

    /**
     * Return the query-string fallback URL for one canary identifier.
     *
     * @param string $identifier Canary identifier.
     * @return string
     */
    protected static function get_varnish_canary_query_url($identifier)
    {
        $identifier = self::sanitize_varnish_canary_identifier($identifier);
        if ('' === $identifier) {
            return '';
        }

        return esc_url_raw(add_query_arg(self::get_varnish_canary_query_var(), $identifier, home_url('/')));
    }

    /**
     * Sanitize one opaque canary identifier.
     *
     * @param mixed $identifier Candidate identifier.
     * @return string
     */
    private static function sanitize_varnish_canary_identifier($identifier)
    {
        $identifier = strtolower(trim((string) $identifier));

        return preg_match('/^[a-f0-9]{32}$/', $identifier) ? $identifier : '';
    }

    /**
     * Return the canary filename for one identifier.
     *
     * @param string $identifier Canary identifier.
     * @return string
     */
    private static function get_varnish_canary_filename($identifier)
    {
        $identifier = self::sanitize_varnish_canary_identifier($identifier);

        return '' !== $identifier ? 'canary-' . $identifier . '.html' : '';
    }

    /**
     * Return the opaque static canary filename for one identifier.
     *
     * @param string $identifier Canary identifier.
     * @return string
     */
    private static function get_varnish_static_canary_filename($identifier)
    {
        $identifier = self::sanitize_varnish_canary_identifier($identifier);

        return '' !== $identifier ? 'static-canary-' . $identifier . '.css' : '';
    }

    /**
     * Return the filesystem path for one canary identifier.
     *
     * @param string $identifier Canary identifier.
     * @return string
     */
    private static function get_varnish_canary_path($identifier, $static = false)
    {
        $directory = self::get_varnish_canary_directory();
        $filename = $static
            ? self::get_varnish_static_canary_filename($identifier)
            : self::get_varnish_canary_filename($identifier);
        if ('' === $directory || '' === $filename) {
            return '';
        }

        return $directory . $filename;
    }

    /**
     * Build a small deterministic body for one canary generation.
     *
     * @param string $identifier Canary identifier.
     * @param int    $generation Canary generation.
     * @return string
     */
    protected static function build_varnish_canary_body($identifier, $generation)
    {
        $identifier = self::sanitize_varnish_canary_identifier($identifier);
        $generation = max(1, min(9, (int) $generation));
        if ('' === $identifier) {
            return '';
        }

        return "<!doctype html>\n"
            . '<html><head><meta charset="utf-8"><title>UltraCache Varnish Canary</title></head>' . "\n"
            . '<body><p>ULTRACACHE-VARNISH-CANARY:' . $identifier . ':GENERATION-' . $generation . '</p></body></html>' . "\n";
    }

    /**
     * Build a deterministic non-HTML static canary body.
     *
     * @param string $identifier Canary identifier.
     * @param int    $generation Canary generation.
     * @return string
     */
    private static function build_varnish_static_canary_body($identifier, $generation)
    {
        $identifier = self::sanitize_varnish_canary_identifier($identifier);
        $generation = max(1, min(9, (int) $generation));
        if ('' === $identifier) {
            return '';
        }

        return '/* ULTRACACHE-VARNISH-CANARY:' . $identifier . ':GENERATION-' . $generation . " */\n"
            . ':root{--ultracache-varnish-canary-generation:' . $generation . ";}\n";
    }


    /**
     * Remove abandoned canary files from interrupted diagnostic runs.
     *
     * @param int $max_age Maximum file age in seconds.
     * @return int
     */
    private static function cleanup_stale_varnish_canaries($max_age = 3600)
    {
        $directory = self::get_varnish_canary_directory();
        if ('' === $directory
            || !function_exists('ultracache_safe_scandir')
            || !function_exists('ultracache_safe_filemtime')
            || !function_exists('ultracache_safe_unlink')) {
            return 0;
        }

        $items = ultracache_safe_scandir($directory, 'varnish canary stale cleanup');
        if (!is_array($items)) {
            return 0;
        }

        $cutoff = time() - max(300, min(DAY_IN_SECONDS, (int) $max_age));
        $removed = 0;
        foreach ($items as $item) {
            $item = sanitize_file_name((string) $item);
            if (!preg_match('/^(?:canary-[a-f0-9]{32}\.html|static-canary-[a-f0-9]{32}\.css)$/', $item)) {
                continue;
            }

            $path = trailingslashit($directory) . $item;
            $modified = ultracache_safe_filemtime($path, 'varnish canary stale cleanup');
            if (false === $modified || (int) $modified >= $cutoff) {
                continue;
            }

            if (ultracache_safe_unlink($path, 'varnish canary stale cleanup')) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Create or replace one local canary generation.
     *
     * @param string $identifier Canary identifier.
     * @param int    $generation Canary generation.
     * @return array|WP_Error
     */
    protected static function write_varnish_canary_generation($identifier, $generation)
    {
        $identifier = self::sanitize_varnish_canary_identifier($identifier);
        if (1 === (int) $generation) {
            self::cleanup_stale_varnish_canaries();
        }
        $path = self::get_varnish_canary_path($identifier);
        $body = self::build_varnish_canary_body($identifier, $generation);
        if ('' === $identifier || '' === $path || '' === $body) {
            return new WP_Error('ultracache_varnish_canary_invalid', __('The Varnish canary path could not be resolved.', 'ultracache'));
        }

        $directory = dirname($path);
        if (!function_exists('ultracache_safe_mkdir') || !ultracache_safe_mkdir($directory, 0755, true, 'varnish canary directory')) {
            return new WP_Error('ultracache_varnish_canary_directory_failed', __('The Varnish canary directory could not be created.', 'ultracache'));
        }

        $index_path = trailingslashit($directory) . 'index.php';
        if (function_exists('ultracache_safe_file_put_contents')) {
            ultracache_safe_file_put_contents(
                $index_path,
                "<?php\n// Silence is golden.\n",
                0,
                'varnish canary directory index'
            );
        }

        $written = function_exists('ultracache_safe_file_put_contents')
            ? ultracache_safe_file_put_contents($path, $body, 0, 'varnish canary generation')
            : false;
        if (false === $written) {
            return new WP_Error('ultracache_varnish_canary_write_failed', __('The Varnish canary file could not be written.', 'ultracache'));
        }

        return array(
            'identifier' => $identifier,
            'generation' => max(1, min(9, (int) $generation)),
            'filename' => self::get_varnish_canary_filename($identifier),
            'path' => $path,
            'body' => $body,
            'bodySha256' => hash('sha256', $body),
            'applicationUrl' => self::get_varnish_canary_application_url($identifier),
            'queryUrl' => self::get_varnish_canary_query_url($identifier),
            'directUrl' => self::get_varnish_canary_direct_url(self::get_varnish_canary_filename($identifier)),
        );
    }


    /**
     * Create or replace one opaque static canary generation.
     *
     * The .css route deliberately has a non-HTML content type and follows a
     * normal static-asset cache path so the
     * HTML-only flush proof can verify that static objects remain cached.
     *
     * @param string $identifier Canary identifier.
     * @param int    $generation Canary generation.
     * @return array|WP_Error
     */
    protected static function write_varnish_static_canary_generation($identifier, $generation)
    {
        $identifier = self::sanitize_varnish_canary_identifier($identifier);
        if (1 === (int) $generation) {
            self::cleanup_stale_varnish_canaries();
        }
        $path = self::get_varnish_canary_path($identifier, true);
        $body = self::build_varnish_static_canary_body($identifier, $generation);
        if ('' === $identifier || '' === $path || '' === $body) {
            return new WP_Error('ultracache_varnish_static_canary_invalid', __('The static Varnish canary path could not be resolved.', 'ultracache'));
        }

        $directory = dirname($path);
        if (!function_exists('ultracache_safe_mkdir') || !ultracache_safe_mkdir($directory, 0755, true, 'varnish static canary directory')) {
            return new WP_Error('ultracache_varnish_static_canary_directory_failed', __('The static Varnish canary directory could not be created.', 'ultracache'));
        }

        $written = function_exists('ultracache_safe_file_put_contents')
            ? ultracache_safe_file_put_contents($path, $body, 0, 'varnish static canary generation')
            : false;
        if (false === $written) {
            return new WP_Error('ultracache_varnish_static_canary_write_failed', __('The static Varnish canary file could not be written.', 'ultracache'));
        }

        return array(
            'identifier' => $identifier,
            'generation' => max(1, min(9, (int) $generation)),
            'filename' => self::get_varnish_static_canary_filename($identifier),
            'path' => $path,
            'body' => $body,
            'bodySha256' => hash('sha256', $body),
            'directUrl' => self::get_varnish_canary_direct_url(self::get_varnish_static_canary_filename($identifier)),
        );
    }

    /**
     * Remove one opaque static canary file.
     *
     * @param string $identifier Canary identifier.
     * @return bool
     */
    protected static function delete_varnish_static_canary($identifier)
    {
        $path = self::get_varnish_canary_path($identifier, true);
        if ('' === $path || !function_exists('ultracache_safe_unlink')) {
            return false;
        }

        return ultracache_safe_unlink($path, 'varnish static canary cleanup');
    }

    /**
     * Remove one local canary file.
     *
     * @param string $identifier Canary identifier.
     * @return bool
     */
    protected static function delete_varnish_canary($identifier)
    {
        $path = self::get_varnish_canary_path($identifier);
        if ('' === $path || !function_exists('ultracache_safe_unlink')) {
            return false;
        }

        return ultracache_safe_unlink($path, 'varnish canary cleanup');
    }

    /**
     * Read the current local canary body.
     *
     * @param string $identifier Canary identifier.
     * @return string
     */
    private static function read_varnish_canary_body($identifier)
    {
        $path = self::get_varnish_canary_path($identifier);
        if ('' === $path || !function_exists('ultracache_safe_file_get_contents')) {
            return '';
        }

        $body = ultracache_safe_file_get_contents(
            $path,
            'varnish canary public response',
            true,
            array(self::get_varnish_canary_directory())
        );

        return is_string($body) ? $body : '';
    }

    /**
     * Serve the WordPress-routed canary response before normal frontend output.
     *
     * @return void
     */
    public function maybe_serve_varnish_canary()
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $identifier = get_query_var(self::get_varnish_canary_query_var(), '');
        $identifier = self::sanitize_varnish_canary_identifier($identifier);
        if ('' === $identifier) {
            $request_uri = ultracache_server_value('REQUEST_URI');
            $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
            if (preg_match('#/ultracache-varnishtest/([a-f0-9]{32})/?$#i', $request_path, $matches)) {
                $identifier = self::sanitize_varnish_canary_identifier($matches[1]);
            }
        }
        if ('' === $identifier) {
            return;
        }

        $body = self::read_varnish_canary_body($identifier);
        if ('' === $body) {
            status_header(404);
            nocache_headers();
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'UltraCache Varnish canary not found.';
            exit;
        }

        if (function_exists('header_remove')) {
            header_remove('Set-Cookie');
            header_remove('Pragma');
            header_remove('Expires');
        }

        $etag = '"uc-varnish-' . hash('sha256', $body) . '"';
        status_header(200);
        header('Content-Type: text/html; charset=UTF-8');
        $cache_control = 'public, max-age=120, s-maxage=120, must-revalidate';
        if (method_exists(static::class, 'is_varnish_origin_revalidation_applicable')
            && self::is_varnish_origin_revalidation_applicable()
            && method_exists(static::class, 'get_varnish_automation_policy')) {
            $automation = self::get_varnish_automation_policy(self::get_dashboard_settings());
            $stale_seconds = max(0, min(86400, absint($automation['staleWhileRevalidateSeconds'] ?? 0)));
            if ($stale_seconds > 0) {
                $cache_control .= ', stale-while-revalidate=' . $stale_seconds;
                header('X-UltraCache-Stale-While-Revalidate: ' . $stale_seconds);
            }
        }
        header('Cache-Control: ' . $cache_control);
        header('ETag: ' . $etag);
        header('X-UltraCache-Varnish-Canary: 1');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UltraCache-generated canary HTML is read only from the validated dedicated canary directory.
        exit;
    }

    /**
     * Generate one cryptographically opaque canary identifier.
     *
     * @return string
     */
    protected static function generate_varnish_canary_identifier()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $throwable) {
            return md5(wp_generate_uuid4() . '|' . microtime(true) . '|' . wp_rand());
        }
    }
}
