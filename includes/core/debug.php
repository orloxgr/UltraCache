<?php
/**
 * Debug, runtime-control, request-input, and operation-budget helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return Redis credentials from wp-config.php constants.
 *
 * WP_REDIS_PASSWORD may be a string or a Redis ACL array in the form
 * array('username', 'password'). WP_REDIS_USERNAME is also supported
 * when the password constant is a scalar.
 *
 * @return array{username:string,password:string,configured:bool,acl:bool}
 */
function ultracache_get_redis_credentials()
{
    $username = '';
    $password = '';
    $acl = false;

    if (defined('WP_REDIS_PASSWORD')) {
        $value = constant('WP_REDIS_PASSWORD');
        if (is_array($value)) {
            $acl = true;
            if (array_key_exists(0, $value)) {
                $username = is_scalar($value[0]) ? trim((string) $value[0]) : '';
            } elseif (isset($value['username']) && is_scalar($value['username'])) {
                $username = trim((string) $value['username']);
            } elseif (isset($value['user']) && is_scalar($value['user'])) {
                $username = trim((string) $value['user']);
            }

            if (array_key_exists(1, $value)) {
                $password = is_scalar($value[1]) ? (string) $value[1] : '';
            } elseif (isset($value['password']) && is_scalar($value['password'])) {
                $password = (string) $value['password'];
            }
        } elseif (is_scalar($value)) {
            $password = (string) $value;
        }
    }

    if ('' === $username && defined('WP_REDIS_USERNAME')) {
        $value = constant('WP_REDIS_USERNAME');
        $username = is_scalar($value) ? trim((string) $value) : '';
    }

    return array(
        'username' => $username,
        'password' => $password,
        'configured' => '' !== $password,
        'acl' => $acl,
    );
}

function ultracache_get_redis_password()
{
    $credentials = ultracache_get_redis_credentials();
    return isset($credentials['password']) ? (string) $credentials['password'] : '';
}

/**
 * Return the UltraCache Varnish secret from wp-config.php.
 *
 * @return string
 */
function ultracache_get_varnish_password()
{
    if (!defined('ULTRACACHE_VARNISH_PASSWORD')) {
        return '';
    }

    $value = constant('ULTRACACHE_VARNISH_PASSWORD');
    return is_scalar($value) ? (string) $value : '';
}


function ultracache_is_sensitive_debug_key($key)
{
    $key = strtolower((string) $key);
    if ('' === $key) {
        return false;
    }

    return 1 === preg_match('/(?:^key$|password|passwd|pwd|secret|token|authorization|cookie|nonce|auth|credential|security|redis[_-]?password|varnish.*key|varnish.*secret|api[_-]?key|access[_-]?key|private[_-]?key|order[_-]?key|client[_-]?secret|ultracache_rt|x[-_]?ultracache[-_]?token)/i', $key);
}

function ultracache_redact_sensitive_string($value)
{
    $value = (string) $value;

    $json_key_pattern = '(?:redis_password|redisPassword|varnish_admin_secret|varnishCliKey|password|passwd|pwd|secret|token|authorization|cookie|nonce|auth|credential|security|api[_-]?key|access[_-]?key|private[_-]?key|order[_-]?key|client[_-]?secret|key|ultracache_rt|x[-_]?ultracache[-_]?token)';
    $value = preg_replace('/((?:"|\')' . $json_key_pattern . '(?:"|\')\s*:\s*(?:"|\'))[^"\']*((?:"|\'))/i', '$1[redacted]$2', $value);
    $value = preg_replace('/(' . $json_key_pattern . '\s*[=:]\s*)[^,\s&}\]]+/i', '$1[redacted]', $value);
    $value = preg_replace('/((?:password|passwd|pwd|secret|token|nonce|auth|credential|security|key)=)([^&\s]+)/i', '$1[redacted]', $value);
    $value = preg_replace('/((?:ultracache_rt|ultracache_revalidate|ultracache_profile_bypass|ultracache_store_profile|ultracache_store_profile_verbose|ultracache_store_profile_verbose_settings|ultracache_callback_profile|ultracache_profile_run)=)([^&\s]+)/i', '$1[redacted]', $value);

    if (preg_match('/(?:bearer\s+|basic\s+)[a-z0-9._~+\/=:-]+/i', $value)) {
        $value = preg_replace('/(?:bearer\s+|basic\s+)[a-z0-9._~+\/=:-]+/i', '[redacted]', $value);
    }

    return $value;
}

function ultracache_redact_sensitive_debug_value($key, $value, $depth = 0)
{
    if (ultracache_is_sensitive_debug_key($key)) {
        return '[redacted]';
    }

    if ($depth > 8) {
        return is_scalar($value) || null === $value ? ultracache_redact_sensitive_string((string) $value) : '[truncated]';
    }

    if (is_array($value)) {
        $redacted = array();
        foreach ($value as $child_key => $child_value) {
            $redacted[$child_key] = ultracache_redact_sensitive_debug_value($child_key, $child_value, $depth + 1);
        }
        return $redacted;
    }

    if (is_string($value)) {
        return ultracache_redact_sensitive_string($value);
    }

    return $value;
}

function ultracache_redact_sensitive_debug_context(array $context)
{
    $redacted = array();
    foreach ($context as $key => $value) {
        $redacted[$key] = ultracache_redact_sensitive_debug_value($key, $value, 0);
    }
    return $redacted;
}

function ultracache_debug_log($message, array $context = array())
{
    /**
     * Fires when UltraCache emits a debug event. Sensitive values are redacted before hooks receive the context.
     *
     * @param string $message Debug message.
     * @param array  $context Context data.
     */
    if (function_exists('ultracache_redact_sensitive_debug_context')) {
        $context = ultracache_redact_sensitive_debug_context($context);
    }
    do_action('ultracache_debug_log', (string) $message, $context);
}


function ultracache_server_value($key)
{
    if (!is_string($key) || '' === $key) {
        return '';
    }

    $value = null;
    if (function_exists('filter_input')) {
        $value = filter_input(INPUT_SERVER, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
    }

    if (null === $value || false === $value) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Fallback only when filter_input() does not expose server values.
        $value = isset($_SERVER[$key]) ? wp_unslash($_SERVER[$key]) : '';
    }

    return is_scalar($value) ? (string) $value : '';
}

function ultracache_server_flag_enabled($key)
{
    $value = strtolower(ultracache_server_value($key));
    return '' !== $value && 'off' !== $value && '0' !== $value;
}

function ultracache_query_value($key)
{
    if (!is_string($key) || '' === $key) {
        return '';
    }

    $value = null;
    if (function_exists('filter_input')) {
        $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
    }

    if (null === $value || false === $value) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- Fallback only when filter_input() does not expose query values.
        $value = isset($_GET[$key]) ? wp_unslash($_GET[$key]) : '';
    }

    return is_scalar($value) ? (string) $value : '';
}


function ultracache_parse_size_to_bytes($size)
{
    $size = trim((string) $size);
    if ('' === $size || '-1' === $size) {
        return 0;
    }

    if (!preg_match('/^([0-9]+)\s*([kmg])?b?$/i', $size, $matches)) {
        return max(0, (int) $size);
    }

    $bytes = (int) $matches[1];
    $unit = isset($matches[2]) ? strtolower((string) $matches[2]) : '';
    if ('g' === $unit) {
        $bytes *= 1024 * 1024 * 1024;
    } elseif ('m' === $unit) {
        $bytes *= 1024 * 1024;
    } elseif ('k' === $unit) {
        $bytes *= 1024;
    }

    return max(0, $bytes);
}

function ultracache_is_cli_context()
{
    return (defined('WP_CLI') && WP_CLI) || 'cli' === PHP_SAPI;
}

function ultracache_get_php_max_execution_time_seconds()
{
    return max(0, (int) ini_get('max_execution_time'));
}

function ultracache_get_safe_operation_budget($context = 'rest', $requested = null, $hard_cap = null)
{
    $context = sanitize_key((string) $context);
    $is_cli = ultracache_is_cli_context();
    $max_execution = function_exists('ultracache_get_php_max_execution_time_seconds')
        ? ultracache_get_php_max_execution_time_seconds()
        : max(0, (int) ini_get('max_execution_time'));
    $memory_limit = ultracache_parse_size_to_bytes((string) ini_get('memory_limit'));

    // A page warm must never invent a shorter execution deadline than PHP itself.
    // max_execution_time=0 means unlimited, represented by a zero-second budget.
    if (0 === strpos($context, 'warm_url_') || 'cron_warm' === $context) {
        return array(
            'context' => $context,
            'started_at' => microtime(true),
            'seconds' => $max_execution > 0 ? $max_execution : 0,
            'max_execution_time' => $max_execution,
            'memory_limit_bytes' => $memory_limit,
            'memory_stop_bytes' => $memory_limit > 0 ? (int) floor($memory_limit * 0.80) : 0,
        );
    }

    $default_requested = $is_cli ? 120 : 20;
    if ('cron' === $context || false !== strpos($context, 'warm')) {
        $default_requested = $is_cli ? 120 : 20;
    } elseif (false !== strpos($context, 'background')) {
        $default_requested = 3;
    }

    $requested = null === $requested ? $default_requested : (int) $requested;
    $requested = max(0, $requested);

    $detected = $is_cli ? 300 : 20;
    if ($max_execution > 0) {
        $margin = max(3, min(10, (int) ceil($max_execution * 0.20)));
        $detected = max(3, $max_execution - $margin);
    }

    $cap = null === $hard_cap ? ($is_cli ? 300 : 45) : max(1, (int) $hard_cap);
    $seconds = $requested > 0 ? min($requested, $detected, $cap) : min($detected, $cap);
    $seconds = max(1, (int) $seconds);

    return array(
        'context' => $context,
        'started_at' => microtime(true),
        'seconds' => $seconds,
        'max_execution_time' => $max_execution,
        'memory_limit_bytes' => $memory_limit,
        'memory_stop_bytes' => $memory_limit > 0 ? (int) floor($memory_limit * 0.80) : 0,
    );
}

function ultracache_operation_pause_reason(array $budget)
{
    $seconds = isset($budget['seconds']) ? max(0, (int) $budget['seconds']) : 0;
    $started_at = isset($budget['started_at']) ? (float) $budget['started_at'] : 0.0;
    if ($seconds > 0 && $started_at > 0 && (microtime(true) - $started_at) >= $seconds) {
        return 'time_budget';
    }

    $memory_stop = isset($budget['memory_stop_bytes']) ? max(0, (int) $budget['memory_stop_bytes']) : 0;
    if ($memory_stop > 0 && function_exists('memory_get_usage') && memory_get_usage(true) >= $memory_stop) {
        return 'memory_budget';
    }

    return '';
}

/**
 * Derive the runtime-control secret from the WordPress authentication salts.
 * No generated secret file or database value is required.
 *
 * @return string
 */
function ultracache_runtime_control_secret()
{
    $constant_names = array(
        'AUTH_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_KEY',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_KEY',
        'LOGGED_IN_SALT',
        'NONCE_KEY',
        'NONCE_SALT',
    );
    $material = array();

    foreach ($constant_names as $constant_name) {
        if (!defined($constant_name)) {
            continue;
        }
        $value = constant($constant_name);
        if (!is_scalar($value)) {
            continue;
        }
        $value = (string) $value;
        if ('' === $value || false !== stripos($value, 'put your unique phrase here')) {
            continue;
        }
        $material[] = $constant_name . '=' . $value;
    }

    if (empty($material)) {
        return '';
    }

    return hash_hmac('sha256', 'ultracache-revalidate-v1', implode('|', $material));
}

function ultracache_create_runtime_control_token($secret = '', $issued_at = null)
{
    $secret = is_string($secret) && '' !== trim($secret) ? (string) $secret : ultracache_runtime_control_secret();
    if ('' === $secret) {
        return '';
    }

    $issued_at = null === $issued_at ? time() : (int) $issued_at;
    if ($issued_at <= 0) {
        return '';
    }

    $payload = 'v2|' . (string) $issued_at . '|ultracache-runtime-control';
    $mac = hash_hmac('sha256', $payload, $secret);

    return 'v2:' . (string) $issued_at . ':' . $mac;
}

function ultracache_validate_runtime_control_token($token, $secret = '', $ttl = 900)
{
    $token = is_scalar($token) ? trim((string) $token) : '';
    if ('' === $token || strlen($token) > 160) {
        return false;
    }

    if (function_exists('sanitize_text_field')) {
        $token = sanitize_text_field($token);
    }

    $secret = is_string($secret) && '' !== trim($secret) ? (string) $secret : ultracache_runtime_control_secret();
    if ('' === $secret) {
        return false;
    }

    $parts = explode(':', $token);
    if (3 !== count($parts) || 'v2' !== $parts[0]) {
        return false;
    }

    $issued_at = (int) $parts[1];
    $mac = (string) $parts[2];
    $ttl = max(60, min(3600, (int) $ttl));
    $now = time();
    if ($issued_at <= 0 || $issued_at > ($now + 60) || ($now - $issued_at) > $ttl) {
        return false;
    }

    if (1 !== preg_match('/^[a-f0-9]{64}$/', $mac)) {
        return false;
    }

    $expected = hash_hmac('sha256', 'v2|' . (string) $issued_at . '|ultracache-runtime-control', $secret);

    return function_exists('hash_equals') ? hash_equals($expected, $mac) : $expected === $mac;
}

/**
 * Verify that the current request is an authenticated UltraCache loopback.
 *
 * The generic internal marker is never trusted on its own. Optional context
 * markers allow callers to require the exact warm or CSS scanner contract.
 *
 * @param string $context Optional request context: warm or css.
 * @return bool
 */
function ultracache_is_authenticated_internal_request($context = '')
{
    $internal = function_exists('ultracache_server_value')
        ? sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_INTERNAL_REQUEST'))
        : '';
    if ('1' !== $internal) {
        return false;
    }

    $context = sanitize_key((string) $context);
    if ('warm' === $context) {
        $warm = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_WARM'));
        if ('1' !== $warm) {
            return false;
        }
    } elseif ('css' === $context) {
        $css = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_CSS_BUNDLE'));
        if ('1' !== $css) {
            return false;
        }
    } elseif ('' !== $context) {
        return false;
    }

    $token = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_TOKEN'));
    return '' !== $token
        && function_exists('ultracache_validate_runtime_control_token')
        && ultracache_validate_runtime_control_token($token);
}

