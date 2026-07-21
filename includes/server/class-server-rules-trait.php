<?php
/**
 * UltraCache server-rule and page-cache bootstrap methods.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Server_Rules_Trait
{
    private static function get_browser_cache_htaccess_path()
    {
        return trailingslashit(ultracache_get_wordpress_home_path()) . '.htaccess';
    }

    private static function get_browser_cache_htaccess_block()
    {
        return implode("\n", array(
            '<IfModule mod_mime.c>',
            'AddType application/manifest+json .webmanifest',
            'AddType application/wasm .wasm',
            'AddType image/avif .avif',
            'AddType image/avif-sequence .avifs',
            '</IfModule>',
            '<IfModule mod_expires.c>',
            'ExpiresActive On',
            'ExpiresByType text/html "access plus 0 seconds"',
            'ExpiresByType text/xml "access plus 0 seconds"',
            'ExpiresByType application/xml "access plus 0 seconds"',
            'ExpiresByType application/json "access plus 0 seconds"',
            'ExpiresByType application/manifest+json "access plus 1 year"',
            'ExpiresByType text/css "access plus 1 year"',
            'ExpiresByType text/javascript "access plus 1 year"',
            'ExpiresByType application/javascript "access plus 1 year"',
            'ExpiresByType application/x-javascript "access plus 1 year"',
            'ExpiresByType image/jpeg "access plus 1 year"',
            'ExpiresByType image/png "access plus 1 year"',
            'ExpiresByType image/gif "access plus 1 year"',
            'ExpiresByType image/webp "access plus 1 year"',
            'ExpiresByType image/avif "access plus 1 year"',
            'ExpiresByType image/avif-sequence "access plus 1 year"',
            'ExpiresByType image/svg+xml "access plus 1 year"',
            'ExpiresByType image/x-icon "access plus 1 year"',
            'ExpiresByType font/ttf "access plus 1 year"',
            'ExpiresByType font/otf "access plus 1 year"',
            'ExpiresByType font/woff "access plus 1 year"',
            'ExpiresByType font/woff2 "access plus 1 year"',
            'ExpiresByType application/font-woff "access plus 1 year"',
            'ExpiresByType application/font-woff2 "access plus 1 year"',
            'ExpiresByType application/vnd.ms-fontobject "access plus 1 year"',
            'ExpiresByType application/wasm "access plus 1 year"',
            'ExpiresByType video/mp4 "access plus 1 year"',
            'ExpiresByType video/webm "access plus 1 year"',
            'ExpiresByType video/ogg "access plus 1 year"',
            'ExpiresByType audio/mpeg "access plus 1 year"',
            'ExpiresByType audio/mp4 "access plus 1 year"',
            'ExpiresByType audio/ogg "access plus 1 year"',
            'ExpiresByType audio/wav "access plus 1 year"',
            '</IfModule>',
            '<IfModule mod_headers.c>',
            '<FilesMatch "\\.(css|js|mjs|gif|png|jpe?g|webp|avif|avifs|svg|ico|woff2?|ttf|otf|eot|webmanifest|wasm|mp4|m4v|webm|ogv|ogg|mp3|m4a|wav)$">',
            'Header set Cache-Control "public, max-age=31536000, immutable"',
            '</FilesMatch>',
            '<FilesMatch "\\.(html?|json|xml)$">',
            'Header set Cache-Control "public, max-age=0, must-revalidate"',
            '</FilesMatch>',
            '</IfModule>',
        ));
    }

    private static function write_htaccess_rules_with_verification($path, $contents, $original_contents, $original_exists, $context)
    {
        $context = (string) $context;
        if (!ultracache_is_allowed_writable_path($path, $context)) {
            return false;
        }

        $filesystem = ultracache_get_wp_filesystem();
        if (
            !$filesystem
            || !method_exists($filesystem, 'put_contents')
            || !method_exists($filesystem, 'get_contents')
            || !method_exists($filesystem, 'exists')
            || !method_exists($filesystem, 'delete')
        ) {
            return false;
        }

        $dir = dirname($path);
        if (method_exists($filesystem, 'is_writable')) {
            $writable_path = $original_exists ? $path : $dir;
            if (!$filesystem->is_writable($writable_path)) {
                return false;
            }
        }

        $mode = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
        $written = $filesystem->put_contents($path, (string) $contents, $mode);
        $verified = false;

        if (false !== $written) {
            $read_back = $filesystem->get_contents($path);
            $verified = is_string($read_back)
                && hash_equals(hash('sha256', (string) $contents), hash('sha256', $read_back));
        }

        if ($verified) {
            return true;
        }

        if ($original_exists) {
            $restored = $filesystem->put_contents($path, (string) $original_contents, $mode);
            $restored_contents = false !== $restored ? $filesystem->get_contents($path) : false;
            $rollback_verified = is_string($restored_contents)
                && hash_equals(hash('sha256', (string) $original_contents), hash('sha256', $restored_contents));

            if (!$rollback_verified) {
                ultracache_debug_log('htaccess rules write and rollback failed', array('path' => $path, 'context' => $context));
            }

            return false;
        }

        if ($filesystem->exists($path)) {
            $filesystem->delete($path, false, 'f');
        }

        if ($filesystem->exists($path)) {
            ultracache_debug_log('htaccess rules write cleanup failed', array('path' => $path, 'context' => $context));
        }

        return false;
    }

    private static function write_browser_cache_rules_with_verification($path, $contents, $original_contents, $original_exists)
    {
        return self::write_htaccess_rules_with_verification($path, $contents, $original_contents, $original_exists, 'sync_browser_cache_rules');
    }

    public static function sync_browser_cache_rules($enabled = null)
    {
        $begin = '# BEGIN UltraCache Browser Cache';
        $end   = '# END UltraCache Browser Cache';

        if (null === $enabled) {
            $settings = self::get_settings();
            $enabled = !empty($settings['browser_cache_rules']);
        }

        $path = self::get_browser_cache_htaccess_path();
        $original_exists = file_exists($path);
        $contents = $original_exists ? (string) ultracache_safe_file_get_contents($path, 'sync_browser_cache_rules') : '';
        $has_block = (false !== strpos($contents, $begin) && false !== strpos($contents, $end));

        if ($original_exists && !ultracache_path_is_writable($path)) {
            return !$enabled && !$has_block;
        }
        $pattern  = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R*/s';
        $updated  = (string) preg_replace($pattern, '', $contents);
        $updated  = rtrim($updated);

        if ($enabled) {
            $block = $begin . "\n" . self::get_browser_cache_htaccess_block() . "\n" . $end;
            $updated = '' === $updated ? $block : ($updated . "\n\n" . $block);
        }

        $updated = '' === trim($updated) ? '' : (rtrim($updated) . "\n");

        if ($updated === $contents) {
            return true;
        }

        $dir = dirname($path);
        if (!file_exists($dir) && !ultracache_safe_mkdir($dir, 0755, true, 'sync_browser_cache_rules') && !file_exists($dir)) {
            return false;
        }

        return self::write_browser_cache_rules_with_verification($path, $updated, $contents, $original_exists);
    }

    private static function get_apache_static_html_delivery_cache_public_path()
    {
        $url = function_exists('ultracache_content_cache_storage_url') ? ultracache_content_cache_storage_url('') : '';
        $path = is_string($url) ? (string) wp_parse_url($url, PHP_URL_PATH) : '';
        if ('' === $path) {
            return '';
        }

        return trailingslashit('/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/'));
    }

    private static function get_apache_static_html_delivery_htaccess_block()
    {
        $cache_path = self::get_apache_static_html_delivery_cache_public_path();
        if ('' === $cache_path) {
            return '';
        }

        $trusted_hosts = array_values(array_unique(array_filter(array_map('ultracache_normalize_host', ultracache_get_trusted_hosts()))));
        $host_targets = array();
        foreach ($trusted_hosts as $trusted_host) {
            $host_folder = preg_replace('#[^a-z0-9._-]#', '-', strtolower((string) $trusted_host));
            if ('' === $trusted_host || '' === $host_folder) {
                continue;
            }
            $host_targets[] = array(
                'condition' => 'RewriteCond %{HTTP_HOST} ^' . preg_quote($trusted_host, '#') . '(?::[0-9]+)?$ [NC]',
                'folder'    => $host_folder,
            );
        }
        if (empty($host_targets)) {
            return '';
        }

        $settings = self::get_settings();
        $gzip_enabled = !empty($settings['gzip_enabled']);
        $brotli_enabled = !empty($settings['brotli_enabled']);
        $variant_policy = ultracache_get_html_variant_policy($settings);
        $active_html_buckets = (array) $variant_policy['buckets'];
        $vary_accept = !empty($variant_policy['vary_accept']);
        $send_variant_header = !empty($settings['debug_headers_enabled']) || !empty($settings['varnish_cli_enabled']);
        $varnish_html_ttl_minutes = !empty($settings['varnish_cli_enabled'])
            ? max(0, min(525600, absint($settings['varnish_html_ttl_minutes'] ?? 0)))
            : 0;
        $varnish_html_ttl_seconds = $varnish_html_ttl_minutes * MINUTE_IN_SECONDS;
        $varnish_stale_while_revalidate_seconds = $varnish_html_ttl_seconds > 0
            ? max(0, min(86400, absint($settings['varnish_stale_while_revalidate_seconds'] ?? 0)))
            : 0;
        $html_cache_control = $varnish_html_ttl_seconds > 0
            ? 'public, max-age=0, s-maxage=' . (string) $varnish_html_ttl_seconds
                . ($varnish_stale_while_revalidate_seconds > 0 ? ', stale-while-revalidate=' . (string) $varnish_stale_while_revalidate_seconds : '')
            : 'public, max-age=0, must-revalidate';

        $common_conditions = array(
            'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$ [NC]',
            'RewriteCond %{HTTP:X-UltraCache-Force-Refresh} !^(?:1|true)$ [NC]',
            'RewriteCond %{QUERY_STRING} ^$',
            'RewriteCond %{HTTP:Cookie} !(wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_) [NC]',
            'RewriteCond %{REQUEST_URI} !(^|/)(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php|wp-comments-post\.php|admin-ajax\.php|wc-api|cart|checkout|my-account|order-pay|order-received|add-payment-method|lost-password)(/|$) [NC]',
            'RewriteCond %{REQUEST_URI} !\.[A-Za-z0-9]{2,8}$ [NC]',
            'RewriteCond %{HTTP:Accept} (text/html|\*/\*) [NC]',
        );

        $lines = array(
            '<FilesMatch "^index-(orig|webp|avif)\.html(?:\.(?:gz|br))?$">',
            'FileETag MTime Size',
            '</FilesMatch>',
            '<IfModule mod_headers.c>',
            '<FilesMatch "^index-(orig|webp|avif)\.html$">',
            'Header set Cache-Control "' . $html_cache_control . '"',
            'Header set X-Ultra-Cache-Source "apache-static"',
            'Header set X-UltraCache-Encoding "identity"',
            $varnish_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Cacheable "1"' : '',
            $varnish_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Surrogate-TTL "' . (string) $varnish_html_ttl_seconds . '"' : '',
            $varnish_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Stale-While-Revalidate "' . (string) $varnish_stale_while_revalidate_seconds . '"' : '',
        );
        if ($vary_accept) {
            $lines[] = 'Header merge Vary Accept';
        }
        $lines[] = 'Header merge Vary Accept-Encoding';
        $lines[] = '</FilesMatch>';

        if ($gzip_enabled) {
            $gzip_lines = array(
                '<FilesMatch "^index-(orig|webp|avif)\.html\.gz$">',
                'Header set Content-Type "text/html; charset=UTF-8"',
                'Header set Content-Encoding "gzip"',
                'Header set Cache-Control "' . $html_cache_control . '"',
                'Header set X-Ultra-Cache-Source "apache-static"',
                'Header set X-UltraCache-Encoding "gzip"',
                $varnish_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Cacheable "1"' : '',
                $varnish_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Surrogate-TTL "' . (string) $varnish_html_ttl_seconds . '"' : '',
            $varnish_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Stale-While-Revalidate "' . (string) $varnish_stale_while_revalidate_seconds . '"' : '',
            );
            if ($vary_accept) {
                $gzip_lines[] = 'Header merge Vary Accept';
            }
            $gzip_lines[] = 'Header merge Vary Accept-Encoding';
            $gzip_lines[] = '</FilesMatch>';
            $lines = array_merge($lines, $gzip_lines);
        }

        if ($brotli_enabled) {
            $brotli_lines = array(
                '<FilesMatch "^index-(orig|webp|avif)\.html\.br$">',
                'Header set Content-Type "text/html; charset=UTF-8"',
                'Header set Content-Encoding "br"',
                'Header set Cache-Control "' . $html_cache_control . '"',
                'Header set X-Ultra-Cache-Source "apache-static"',
                'Header set X-UltraCache-Encoding "brotli"',
                $varnish_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Cacheable "1"' : '',
                $varnish_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Surrogate-TTL "' . (string) $varnish_html_ttl_seconds . '"' : '',
            $varnish_html_ttl_seconds > 0 ? 'Header set X-UltraCache-Stale-While-Revalidate "' . (string) $varnish_stale_while_revalidate_seconds . '"' : '',
            );
            if ($vary_accept) {
                $brotli_lines[] = 'Header merge Vary Accept';
            }
            $brotli_lines[] = 'Header merge Vary Accept-Encoding';
            $brotli_lines[] = '</FilesMatch>';
            $lines = array_merge($lines, $brotli_lines);
        }

        $lines = array_merge($lines, array(
            '<FilesMatch "^index-(orig|webp|avif)\.html(?:\.(?:gz|br))?$">',
            'Header always unset Content-Type "expr=%{REQUEST_STATUS} == 304"',
            'Header always unset Content-Encoding "expr=%{REQUEST_STATUS} == 304"',
            'Header always unset Content-Length "expr=%{REQUEST_STATUS} == 304"',
            '</FilesMatch>',
        ));

        $lines = array_values(array_filter($lines, static function ($line) {
            return '' !== (string) $line;
        }));

        if ($send_variant_header) {
            foreach ($active_html_buckets as $active_html_bucket) {
                if (!in_array($active_html_bucket, array('orig', 'webp', 'avif'), true)) {
                    continue;
                }
                $lines[] = '<FilesMatch "^index-' . $active_html_bucket . '\.html(?:\.(?:gz|br))?$">';
                $lines[] = 'Header set X-UltraCache-Variant "' . $active_html_bucket . '"';
                $lines[] = '</FilesMatch>';
            }
        }

        $lines = array_merge($lines, array(
            '</IfModule>',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            '# UltraCache direct static HTML delivery: conservative queryless anonymous page-cache aliases.',
        ));

        $append_rule = static function (array $host_target, array $extra_conditions, $file, $rule_pattern) use (&$lines, $common_conditions, $cache_path) {
            foreach (array_merge($common_conditions, array($host_target['condition']), $extra_conditions) as $condition) {
                $lines[] = $condition;
            }
            $target = $cache_path . $host_target['folder'] . '/' . $file;
            $lines[] = 'RewriteCond %{DOCUMENT_ROOT}' . $target . ' -f';
            $lines[] = 'RewriteRule ' . $rule_pattern . ' ' . $target . ' [L]';
        };

        $bucket_rules = array();
        $avif_accept_conditions = array(
            'RewriteCond %{HTTP:Accept} "(^|,)[[:space:]]*image/avif([[:space:]]*;[^,]*)?([[:space:]]*,|$)" [NC]',
            'RewriteCond %{HTTP:Accept} "!(^|,)[[:space:]]*image/avif[[:space:]]*;[^,]*q[[:space:]]*=[[:space:]]*0(\.0+)?([[:space:]]*(;|,|$))" [NC]',
        );
        $webp_accept_conditions = array(
            'RewriteCond %{HTTP:Accept} "(^|,)[[:space:]]*image/webp([[:space:]]*;[^,]*)?([[:space:]]*,|$)" [NC]',
            'RewriteCond %{HTTP:Accept} "!(^|,)[[:space:]]*image/webp[[:space:]]*;[^,]*q[[:space:]]*=[[:space:]]*0(\.0+)?([[:space:]]*(;|,|$))" [NC]',
        );
        if (in_array('avif', $active_html_buckets, true)) {
            $bucket_rules[] = array($avif_accept_conditions, 'index/index-avif.html', '^$');
        }
        if (in_array('webp', $active_html_buckets, true)) {
            $bucket_rules[] = array($webp_accept_conditions, 'index/index-webp.html', '^$');
        }
        $bucket_rules[] = array(array(), 'index/index-orig.html', '^$');
        if (in_array('avif', $active_html_buckets, true)) {
            $bucket_rules[] = array($avif_accept_conditions, '$1/index-avif.html', '^(.+?)/?$');
        }
        if (in_array('webp', $active_html_buckets, true)) {
            $bucket_rules[] = array($webp_accept_conditions, '$1/index-webp.html', '^(.+?)/?$');
        }
        $bucket_rules[] = array(array(), '$1/index-orig.html', '^(.+?)/?$');

        if ($brotli_enabled || $gzip_enabled) {
            $lines[] = '<IfModule mod_headers.c>';
            $explicit_encoding_quality_condition = 'RewriteCond %{HTTP:Accept-Encoding} "!(^|,)[[:space:]]*(br|gzip)[[:space:]]*;[^,]*q[[:space:]]*=" [NC]';
            $brotli_accept_condition = 'RewriteCond %{HTTP:Accept-Encoding} "(^|,)[[:space:]]*br([[:space:]]*;[^,]*)?([[:space:]]*,|$)" [NC]';
            $gzip_accept_condition = 'RewriteCond %{HTTP:Accept-Encoding} "(^|,)[[:space:]]*gzip([[:space:]]*;[^,]*)?([[:space:]]*,|$)" [NC]';
            foreach ($host_targets as $host_target) {
                foreach ($bucket_rules as $bucket_rule) {
                    list($bucket_conditions, $file, $rule_pattern) = $bucket_rule;

                    if ($brotli_enabled) {
                        $append_rule(
                            $host_target,
                            array_merge(
                                $bucket_conditions,
                                array(
                                    $brotli_accept_condition,
                                    $explicit_encoding_quality_condition,
                                )
                            ),
                            $file . '.br',
                            $rule_pattern
                        );
                    }

                    if ($gzip_enabled) {
                        $append_rule(
                            $host_target,
                            array_merge(
                                $bucket_conditions,
                                array(
                                    $gzip_accept_condition,
                                    $explicit_encoding_quality_condition,
                                )
                            ),
                            $file . '.gz',
                            $rule_pattern
                        );
                    }
                }
            }
            $lines[] = '</IfModule>';
        }

        foreach ($host_targets as $host_target) {
            foreach ($bucket_rules as $bucket_rule) {
                list($bucket_conditions, $file, $rule_pattern) = $bucket_rule;
                $append_rule($host_target, $bucket_conditions, $file, $rule_pattern);
            }
        }

        $lines[] = '</IfModule>';

        return implode("\n", $lines) . "\n";
    }

    public static function sync_apache_static_html_delivery_rules($enabled = null)
    {
        $begin = '# BEGIN UltraCache Apache Static HTML';
        $end   = '# END UltraCache Apache Static HTML';

        if (null === $enabled) {
            $settings = self::get_settings();
            $enabled = !empty($settings['apache_static_html_delivery']) && !empty($settings['enabled']);
        }

        $path = self::get_browser_cache_htaccess_path();
        $original_exists = file_exists($path);
        $contents = $original_exists ? (string) ultracache_safe_file_get_contents($path, 'sync_apache_static_html_delivery_rules') : '';
        $has_block = (false !== strpos($contents, $begin) && false !== strpos($contents, $end));

        if ($original_exists && !ultracache_path_is_writable($path)) {
            return !$enabled && !$has_block;
        }
        $pattern = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R*/s';
        $updated = (string) preg_replace($pattern, '', $contents);
        $updated = rtrim($updated);

        if ($enabled) {
            $block_body = self::get_apache_static_html_delivery_htaccess_block();
            if ('' === $block_body) {
                return false;
            }
            $block = $begin . "\n" . $block_body . "\n" . $end;
            $updated = '' === $updated ? $block : ($updated . "\n\n" . $block);
        }

        $updated = '' === trim($updated) ? '' : (rtrim($updated) . "\n");

        if ($updated === $contents) {
            return true;
        }

        $dir = dirname($path);
        if (!file_exists($dir) && !ultracache_safe_mkdir($dir, 0755, true, 'sync_apache_static_html_delivery_rules') && !file_exists($dir)) {
            return false;
        }

        return self::write_htaccess_rules_with_verification($path, $updated, $contents, $original_exists, 'sync_apache_static_html_delivery_rules');
    }

    private static function get_engine_class()
    {
        $candidates = array('Ultra_Cache_Engine');
        foreach ($candidates as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    public static function sync_page_cache_bootstrap($enabled = null, $update_wp_config = true)
    {
        if (null === $enabled) {
            $settings = self::get_dashboard_settings();
            $enabled = !empty($settings['pageCacheEnabled']);
        }

        $enabled = (bool) $enabled;

        if ($update_wp_config) {
            $result = self::set_wp_cache_flag($enabled);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        $engine_class = self::get_engine_class();
        if (!$engine_class) {
            return true;
        }

        if ($enabled) {
            if (method_exists($engine_class, 'setup_advanced_cache')) {
                $engine_class::setup_advanced_cache();
            }
        } elseif (method_exists($engine_class, 'maybe_remove_advanced_cache')) {
            $engine_class::maybe_remove_advanced_cache();
        }

        return true;
    }
}
