<?php
/**
 * UltraCache wp-config.php management methods.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Config_Manager_Trait
{
    private static function get_wp_config_path()
    {
        $path = ultracache_loaded_wp_config_path();
        $filesystem = ultracache_get_wp_filesystem();
        if ('' === $path || !$filesystem) {
            return false;
        }

        if (method_exists($filesystem, 'exists') && !$filesystem->exists($path)) {
            return false;
        }

        if (method_exists($filesystem, 'is_readable') && !$filesystem->is_readable($path)) {
            return false;
        }

        return $path;
    }

    private static function get_managed_constants_block($manage_wp_cache, $redis_password, $varnish_password)
    {
        $lines = array();

        if ($manage_wp_cache) {
            $lines[] = "if ( ! defined( 'WP_CACHE' ) ) {";
            $lines[] = "\tdefine( 'WP_CACHE', true );";
            $lines[] = '}';
        }

        if ('' !== $redis_password) {
            if (!empty($lines)) {
                $lines[] = '';
            }
            $lines[] = "if ( ! defined( 'WP_REDIS_PASSWORD' ) ) {";
            // var_export() intentionally creates a valid PHP string literal for wp-config.php; it is not debug output.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
            $lines[] = "\tdefine( 'WP_REDIS_PASSWORD', " . var_export($redis_password, true) . ' );';
            $lines[] = '}';
        }

        if ('' !== $varnish_password) {
            if (!empty($lines)) {
                $lines[] = '';
            }
            $lines[] = "if ( ! defined( 'ULTRACACHE_VARNISH_PASSWORD' ) ) {";
            // var_export() intentionally creates a valid PHP string literal for wp-config.php; it is not debug output.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
            $lines[] = "\tdefine( 'ULTRACACHE_VARNISH_PASSWORD', " . var_export($varnish_password, true) . ' );';
            $lines[] = '}';
        }

        if (empty($lines)) {
            return '';
        }

        return "/* UltraCache managed constants start */\n"
            . implode("\n", $lines)
            . "\n/* UltraCache managed constants end */\n";
    }

    private static function get_managed_constants_block_contents($contents)
    {
        if (!preg_match(
            '#/\\* UltraCache managed constants start \\*/\\R(.*?)/\\* UltraCache managed constants end \\*/#s',
            (string) $contents,
            $matches
        )) {
            return '';
        }

        return isset($matches[0]) ? (string) $matches[0] : '';
    }

    private static function strip_managed_constants_block($contents)
    {
        $contents = (string) $contents;

        // Remove the managed block together with adjacent blank lines. Older
        // versions removed only the delimited text, leaving the block's trailing
        // newline behind; repeated wp-config.php updates could therefore grow a
        // large empty gap before the next insertion point.
        $pattern = '#(?:[ \t]*\R)*/\* UltraCache managed constants start \*/\R.*?/\* UltraCache managed constants end \*/(?:[ \t]*\R)*#s';
        $updated = preg_replace($pattern, "\n", $contents, 1, $replacements);

        if (!is_string($updated) || 1 !== $replacements) {
            return $contents;
        }

        return $updated;
    }

    private static function get_wp_config_insertion_offset($contents)
    {
        $contents = (string) $contents;
        if ('' === $contents) {
            return false;
        }

        $tokens = token_get_all($contents);
        if (!is_array($tokens) || empty($tokens)) {
            return false;
        }

        $offset = 0;
        $total  = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            $text  = is_array($token) ? (string) $token[1] : (string) $token;
            $offset += strlen($text);

            if (!is_array($token) || T_OPEN_TAG !== $token[0]) {
                continue;
            }

            $base_offset = $offset;
            $scan_offset = $offset;
            $j = $i + 1;

            while ($j < $total) {
                $scan_token = $tokens[$j];
                $scan_text  = is_array($scan_token) ? (string) $scan_token[1] : (string) $scan_token;

                if (is_array($scan_token) && T_WHITESPACE === $scan_token[0]) {
                    $scan_offset += strlen($scan_text);
                    $j++;
                    continue;
                }

                break;
            }

            if ($j < $total && is_array($tokens[$j]) && T_DECLARE === $tokens[$j][0]) {
                $offset = $scan_offset;
                for (; $j < $total; $j++) {
                    $next      = $tokens[$j];
                    $next_text = is_array($next) ? (string) $next[1] : (string) $next;
                    $offset += strlen($next_text);

                    if (';' === $next_text) {
                        return $offset;
                    }
                }

                return false;
            }

            return $base_offset;
        }

        return false;
    }

    private static function normalize_wp_config_define_name($raw)
    {
        $raw = trim((string) $raw);
        if ('' === $raw) {
            return '';
        }

        $quote = substr($raw, 0, 1);
        if (("'" === $quote || '"' === $quote) && $quote === substr($raw, -1)) {
            $raw = substr($raw, 1, -1);
        }

        return stripslashes($raw);
    }

    private static function classify_wp_config_boolean_value($raw)
    {
        $raw = strtolower(trim((string) $raw));
        if ('true' === $raw) {
            return 'true';
        }
        if ('false' === $raw) {
            return 'false';
        }

        return 'other';
    }

    private static function decode_managed_string_literal($raw)
    {
        $raw = trim((string) $raw);
        if (strlen($raw) < 2 || "'" !== substr($raw, 0, 1) || "'" !== substr($raw, -1)) {
            return '';
        }

        $value = substr($raw, 1, -1);
        return str_replace(array('\\\\', "\\'"), array('\\', "'"), $value);
    }

    private static function find_wp_config_define_statements($contents, array $constant_names)
    {
        $contents = (string) $contents;
        $constant_names = array_values(array_unique(array_filter(array_map('strval', $constant_names))));
        if ('' === $contents || empty($constant_names)) {
            return array();
        }

        $wanted = array_fill_keys($constant_names, true);
        $parse_contents = $contents;
        $prefix_length = 0;
        if (false === strpos($parse_contents, '<?')) {
            $parse_contents = "<?php\n" . $parse_contents;
            $prefix_length = 6;
        }

        $tokens = token_get_all($parse_contents);
        if (!is_array($tokens) || empty($tokens)) {
            return array();
        }

        $matches = array();
        $offset  = 0;
        $total   = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            $text  = is_array($token) ? (string) $token[1] : (string) $token;
            $len   = strlen($text);

            if (!is_array($token) || T_STRING !== $token[0] || 'define' !== strtolower($text)) {
                $offset += $len;
                continue;
            }

            $start_offset = $offset;
            $cursor       = $offset + $len;
            $j            = $i + 1;

            while ($j < $total) {
                $next      = $tokens[$j];
                $next_text = is_array($next) ? (string) $next[1] : (string) $next;
                if (is_array($next) && in_array($next[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                    $cursor += strlen($next_text);
                    $j++;
                    continue;
                }
                break;
            }

            if ($j >= $total || '(' !== (is_array($tokens[$j]) ? (string) $tokens[$j][1] : (string) $tokens[$j])) {
                $offset += $len;
                continue;
            }

            $cursor += 1;
            $j++;
            $depth       = 0;
            $current_arg = '';
            $args        = array();
            $closed      = false;

            for (; $j < $total; $j++) {
                $part      = $tokens[$j];
                $part_text = is_array($part) ? (string) $part[1] : (string) $part;
                $cursor   += strlen($part_text);

                if ('(' === $part_text) {
                    $depth++;
                    $current_arg .= $part_text;
                    continue;
                }

                if (')' === $part_text) {
                    if ($depth > 0) {
                        $depth--;
                        $current_arg .= $part_text;
                        continue;
                    }

                    $args[] = $current_arg;
                    $current_arg = '';
                    $closed = true;
                    $j++;
                    break;
                }

                if (',' === $part_text && 0 === $depth) {
                    $args[] = $current_arg;
                    $current_arg = '';
                    continue;
                }

                $current_arg .= $part_text;
            }

            if (!$closed) {
                $offset += $len;
                continue;
            }

            while ($j < $total) {
                $tail      = $tokens[$j];
                $tail_text = is_array($tail) ? (string) $tail[1] : (string) $tail;
                $cursor   += strlen($tail_text);
                if (';' === $tail_text) {
                    break;
                }
                $j++;
            }

            if ($j >= $total) {
                $offset += $len;
                continue;
            }

            $name = isset($args[0]) ? self::normalize_wp_config_define_name($args[0]) : '';
            if (!isset($wanted[$name])) {
                $offset += $len;
                continue;
            }

            $content_start = max(0, $start_offset - $prefix_length);
            $content_end = max($content_start, $cursor - $prefix_length);
            $matches[$name][] = array(
                'start'     => $content_start,
                'end'       => $content_end,
                'statement' => substr($contents, $content_start, $content_end - $content_start),
                'value_raw' => isset($args[1]) ? trim((string) $args[1]) : '',
            );

            $offset = $cursor;
            $i      = $j;
        }

        return $matches;
    }

    private static function get_wp_config_constant_inventory($contents)
    {
        $names = array('WP_CACHE', 'WP_REDIS_PASSWORD', 'ULTRACACHE_VARNISH_PASSWORD');
        $managed_block = self::get_managed_constants_block_contents($contents);
        $external_contents = self::strip_managed_constants_block($contents);
        $managed_matches = self::find_wp_config_define_statements($managed_block, $names);
        $external_matches = self::find_wp_config_define_statements($external_contents, $names);

        $managed_values = array(
            'WP_REDIS_PASSWORD' => '',
            'ULTRACACHE_VARNISH_PASSWORD' => '',
        );

        foreach (array_keys($managed_values) as $name) {
            if (!empty($managed_matches[$name][0]['value_raw'])) {
                $managed_values[$name] = self::decode_managed_string_literal($managed_matches[$name][0]['value_raw']);
            }
        }

        $wp_cache_status = 'missing';
        if (!empty($external_matches['WP_CACHE'])) {
            $wp_cache_status = 'other';
            foreach ($external_matches['WP_CACHE'] as $match) {
                $classified = self::classify_wp_config_boolean_value($match['value_raw'] ?? '');
                if ('true' === $classified) {
                    $wp_cache_status = 'true';
                    break;
                }
                if ('false' === $classified) {
                    $wp_cache_status = 'false';
                }
            }
        }

        return array(
            'managed_block'   => $managed_block,
            'managed_matches' => $managed_matches,
            'managed_values'  => $managed_values,
            'external_matches'=> $external_matches,
            'external_wp_cache_status' => $wp_cache_status,
        );
    }

    private static function insert_managed_constants_block($contents, $block)
    {
        if ('' === $block) {
            return (string) $contents;
        }

        $offset = self::get_wp_config_insertion_offset($contents);
        if (false === $offset) {
            return new WP_Error('ultracache_wp_config_anchor_not_found', __('Could not locate a safe insertion point for the UltraCache constants in wp-config.php.', 'ultracache'));
        }

        $before = substr((string) $contents, 0, $offset);
        $after  = substr((string) $contents, $offset);
        $prefix = ('' !== $before && !preg_match('/\R\z/', $before)) ? "\n" : '';

        return $before . $prefix . $block . ltrim($after, "\r\n");
    }

    private static function validate_wp_config_contents($contents)
    {
        try {
            $tokens = token_get_all((string) $contents, TOKEN_PARSE);
        } catch (ParseError $error) {
            return new WP_Error('ultracache_wp_config_invalid_php', __('The generated wp-config.php content is not valid PHP.', 'ultracache'));
        }

        // TOKEN_PARSE accepts calls to unknown functions and can therefore accept a
        // malformed `<?phpdefine(...)` sequence as a short opening tag followed by
        // `phpdefine(...)` when short_open_tag is enabled. Require the active config
        // to retain a normal full PHP opening tag before it can be written.
        foreach ($tokens as $token) {
            if (!is_array($token) || T_OPEN_TAG !== $token[0]) {
                continue;
            }

            if (!preg_match('/^<\?php(?:\s|$)/i', (string) $token[1])) {
                return new WP_Error('ultracache_wp_config_invalid_php', __('The generated wp-config.php content is not valid PHP.', 'ultracache'));
            }

            return true;
        }

        return new WP_Error('ultracache_wp_config_invalid_php', __('The generated wp-config.php content is not valid PHP.', 'ultracache'));
    }

    private static function write_wp_config_with_verification($config, $contents, $original_contents)
    {
        $filesystem = ultracache_get_wp_filesystem();
        if (!$filesystem || !method_exists($filesystem, 'put_contents') || !method_exists($filesystem, 'get_contents')) {
            return new WP_Error('ultracache_wp_filesystem_unavailable', __('The WordPress Filesystem API is unavailable for updating wp-config.php.', 'ultracache'));
        }

        if (method_exists($filesystem, 'is_writable') && !$filesystem->is_writable($config)) {
            return new WP_Error('ultracache_wp_config_not_writable', __('wp-config.php is not writable through the WordPress Filesystem API.', 'ultracache'));
        }

        $mode = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
        $written = $filesystem->put_contents($config, (string) $contents, $mode);
        $verified = false;

        if (false !== $written) {
            $read_back = $filesystem->get_contents($config);
            $verified = is_string($read_back)
                && hash_equals(hash('sha256', (string) $contents), hash('sha256', $read_back));
        }

        if ($verified) {
            return true;
        }

        $restored = $filesystem->put_contents($config, (string) $original_contents, $mode);
        $restored_contents = false !== $restored ? $filesystem->get_contents($config) : false;
        $rollback_verified = is_string($restored_contents)
            && hash_equals(hash('sha256', (string) $original_contents), hash('sha256', $restored_contents));

        if (!$rollback_verified) {
            return new WP_Error('ultracache_wp_config_write_and_rollback_failed', __('The wp-config.php update failed and the original content could not be verified after rollback.', 'ultracache'));
        }

        return new WP_Error('ultracache_wp_config_write_failed', __('The wp-config.php update failed verification. The original content was restored.', 'ultracache'));
    }

    private static function normalize_secret_constant_patch(array $settings)
    {
        $patch = array(
            'redis' => array('provided' => false, 'value' => '', 'clear' => false),
            'varnish' => array('provided' => false, 'value' => '', 'clear' => false),
        );

        $map = array(
            'redis' => array('value' => 'redisPassword', 'clear' => 'clearRedisPassword'),
            'varnish' => array('value' => 'varnishCliKey', 'clear' => 'clearVarnishCliKey'),
        );

        foreach ($map as $target => $keys) {
            $clear = !empty($settings[$keys['clear']]);
            $raw_value = array_key_exists($keys['value'], $settings) && is_scalar($settings[$keys['value']])
                ? (string) $settings[$keys['value']]
                : '';
            $provided = '' !== $raw_value;
            $value = $provided ? str_replace("\0", '', $raw_value) : '';

            if ($clear && $provided) {
                $patch[$target]['error'] = new WP_Error('ultracache_secret_update_conflict', __('A password cannot be replaced and removed in the same request.', 'ultracache'));
                continue;
            }

            if (strlen($value) > 4096) {
                $patch[$target]['error'] = new WP_Error('ultracache_secret_too_long', __('Redis and Varnish passwords must not exceed 4096 characters.', 'ultracache'));
                continue;
            }

            $patch[$target] = array(
                'provided' => $provided,
                'value'    => $value,
                'clear'    => $clear,
            );
        }

        return $patch;
    }

    private static function secret_constant_patch_has_changes(array $patch)
    {
        foreach (array('redis', 'varnish') as $target) {
            if (!empty($patch[$target]['provided']) || !empty($patch[$target]['clear'])) {
                return true;
            }
        }

        return false;
    }

    private static function update_wp_config_managed_constants($wp_cache_enabled, array $secret_patch = array())
    {
        foreach (array('redis', 'varnish') as $target) {
            if (isset($secret_patch[$target]['error']) && is_wp_error($secret_patch[$target]['error'])) {
                return $secret_patch[$target]['error'];
            }
        }

        $config = self::get_wp_config_path();
        if (!$config) {
            return new WP_Error('ultracache_wp_config_not_found', __('wp-config.php could not be located.', 'ultracache'));
        }

        $filesystem = ultracache_get_wp_filesystem();
        if (!$filesystem || !method_exists($filesystem, 'get_contents')) {
            return new WP_Error('ultracache_wp_filesystem_unavailable', __('The WordPress Filesystem API is unavailable for reading wp-config.php.', 'ultracache'));
        }

        $raw_contents = $filesystem->get_contents($config);
        if (!is_string($raw_contents) || '' === $raw_contents) {
            return new WP_Error('ultracache_wp_config_read_failed', __('Failed to read wp-config.php through the WordPress Filesystem API.', 'ultracache'));
        }

        $original_contents = $raw_contents;
        $inventory = self::get_wp_config_constant_inventory($original_contents);
        $external = $inventory['external_matches'];
        $managed_values = $inventory['managed_values'];

        $redis_password = isset($managed_values['WP_REDIS_PASSWORD']) ? (string) $managed_values['WP_REDIS_PASSWORD'] : '';
        $varnish_password = isset($managed_values['ULTRACACHE_VARNISH_PASSWORD']) ? (string) $managed_values['ULTRACACHE_VARNISH_PASSWORD'] : '';

        $secret_map = array(
            'redis' => array('constant' => 'WP_REDIS_PASSWORD', 'label' => 'WP_REDIS_PASSWORD'),
            'varnish' => array('constant' => 'ULTRACACHE_VARNISH_PASSWORD', 'label' => 'ULTRACACHE_VARNISH_PASSWORD'),
        );

        foreach ($secret_map as $target => $meta) {
            $change_requested = !empty($secret_patch[$target]['provided']) || !empty($secret_patch[$target]['clear']);
            $externally_defined = !empty($external[$meta['constant']]);

            if ($change_requested && $externally_defined) {
                return new WP_Error(
                    'ultracache_external_secret_constant',
                    sprintf(
                        /* translators: %s: wp-config.php constant name. */
                        __('%s is defined outside the UltraCache managed block and cannot be changed from the dashboard.', 'ultracache'),
                        $meta['label']
                    )
                );
            }

            if ($externally_defined) {
                if ('redis' === $target) {
                    $redis_password = '';
                } else {
                    $varnish_password = '';
                }
                continue;
            }

            if (!empty($secret_patch[$target]['clear'])) {
                if ('redis' === $target) {
                    $redis_password = '';
                } else {
                    $varnish_password = '';
                }
            } elseif (!empty($secret_patch[$target]['provided'])) {
                if ('redis' === $target) {
                    $redis_password = (string) $secret_patch[$target]['value'];
                } else {
                    $varnish_password = (string) $secret_patch[$target]['value'];
                }
            }
        }

        $manage_wp_cache = false;
        if ($wp_cache_enabled) {
            $external_wp_cache = isset($inventory['external_wp_cache_status']) ? (string) $inventory['external_wp_cache_status'] : 'missing';
            if ('true' === $external_wp_cache) {
                $manage_wp_cache = false;
            } elseif ('missing' === $external_wp_cache) {
                $manage_wp_cache = true;
            } else {
                return new WP_Error('ultracache_external_wp_cache_constant', __('WP_CACHE is defined outside the UltraCache managed block with a value that prevents UltraCache from enabling page cache.', 'ultracache'));
            }
        }

        $contents = self::strip_managed_constants_block($original_contents);
        $block = self::get_managed_constants_block($manage_wp_cache, $redis_password, $varnish_password);
        $contents = self::insert_managed_constants_block($contents, $block);
        if (is_wp_error($contents)) {
            return $contents;
        }

        $validation = self::validate_wp_config_contents($contents);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $transaction = array(
            'path'     => $config,
            'original' => $original_contents,
            'updated'  => (string) $contents,
            'changed'  => $contents !== $original_contents,
        );

        if (!$transaction['changed']) {
            return $transaction;
        }

        $write = self::write_wp_config_with_verification($config, $contents, $original_contents);
        if (is_wp_error($write)) {
            return $write;
        }

        return $transaction;
    }

    private static function rollback_wp_config_transaction(array $transaction)
    {
        if (empty($transaction['changed']) || empty($transaction['path']) || !array_key_exists('original', $transaction)) {
            return true;
        }

        $filesystem = ultracache_get_wp_filesystem();
        if (!$filesystem || !method_exists($filesystem, 'put_contents') || !method_exists($filesystem, 'get_contents')) {
            return false;
        }

        $mode = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
        $result = $filesystem->put_contents($transaction['path'], (string) $transaction['original'], $mode);
        if (false === $result) {
            return false;
        }

        $read_back = $filesystem->get_contents($transaction['path']);
        return is_string($read_back)
            && hash_equals(hash('sha256', (string) $transaction['original']), hash('sha256', $read_back));
    }

    public static function get_wp_config_secret_statuses()
    {
        $statuses = array(
            'redis' => array('configured' => false, 'managed' => false, 'external' => false),
            'varnish' => array('configured' => false, 'managed' => false, 'external' => false),
        );

        $config = self::get_wp_config_path();
        $filesystem = ultracache_get_wp_filesystem();
        if (!$config || !$filesystem || !method_exists($filesystem, 'get_contents')) {
            return $statuses;
        }

        $contents = $filesystem->get_contents($config);
        if (!is_string($contents) || '' === $contents) {
            return $statuses;
        }

        $inventory = self::get_wp_config_constant_inventory($contents);
        $runtime_redis = function_exists('ultracache_get_redis_password') ? (string) ultracache_get_redis_password() : '';
        $runtime_varnish = function_exists('ultracache_get_varnish_password') ? (string) ultracache_get_varnish_password() : '';

        $statuses['redis'] = array(
            'configured' => '' !== $runtime_redis || '' !== (string) ($inventory['managed_values']['WP_REDIS_PASSWORD'] ?? ''),
            'managed'    => !empty($inventory['managed_matches']['WP_REDIS_PASSWORD']),
            'external'   => !empty($inventory['external_matches']['WP_REDIS_PASSWORD']),
        );
        $statuses['varnish'] = array(
            'configured' => '' !== $runtime_varnish || '' !== (string) ($inventory['managed_values']['ULTRACACHE_VARNISH_PASSWORD'] ?? ''),
            'managed'    => !empty($inventory['managed_matches']['ULTRACACHE_VARNISH_PASSWORD']),
            'external'   => !empty($inventory['external_matches']['ULTRACACHE_VARNISH_PASSWORD']),
        );

        return $statuses;
    }

    private static function set_wp_cache_flag($enabled = true)
    {
        $result = self::update_wp_config_managed_constants((bool) $enabled, array());
        return is_wp_error($result) ? $result : true;
    }
}
