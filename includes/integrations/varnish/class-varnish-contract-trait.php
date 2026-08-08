<?php
/**
 * Versioned UltraCache HTTP/VCL contract helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Contract_Trait
{
    /**
     * Return the current optional HTTP/VCL contract version.
     *
     * @return int
     */
    private static function get_varnish_http_contract_version()
    {
        return 2;
    }

    /**
     * Return the adapter identifier advertised by the bundled CWP templates.
     *
     * @return string
     */
    private static function get_varnish_http_contract_adapter()
    {
        return 'ultracache-cwp-v2';
    }

    /**
     * Normalize a bounded contract token before placing it in an HTTP header.
     *
     * @param mixed $token Raw token.
     * @return string
     */
    private static function sanitize_varnish_http_contract_token($token)
    {
        $token = trim((string) $token);
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) {
            return '';
        }

        return $token;
    }

    /**
     * Normalize one comma-separated contract capability list.
     *
     * @param mixed $value Raw header value or list.
     * @return array<int,string>
     */
    private static function sanitize_varnish_http_contract_capabilities($value)
    {
        $items = is_array($value) ? $value : preg_split('/\s*,\s*/', strtolower((string) $value));
        $allowed = array(
            'exact-purge',
            'exact-ban',
            'batch-ban',
            'html-ban',
            'host-ban',
            'soft-purge',
            'origin-revalidation',
            'swr',
            'public-esi',
            'private-esi',
            'html-variants',
            'woocommerce-shared-parent',
        );
        $clean = array();
        foreach ((array) $items as $item) {
            $item = sanitize_key((string) $item);
            if (in_array($item, $allowed, true)) {
                $clean[$item] = true;
            }
        }

        return array_keys($clean);
    }

    /**
     * Return whether a contract capability was advertised.
     *
     * @param array  $contract   Contract result.
     * @param string $capability Capability key.
     * @return bool
     */
    private static function varnish_http_contract_has_capability(array $contract, $capability)
    {
        return in_array(
            sanitize_key((string) $capability),
            self::sanitize_varnish_http_contract_capabilities($contract['capabilities'] ?? array()),
            true
        );
    }

    /**
     * Send one bounded request to the optional UltraCache VCL contract.
     *
     * @param array  $endpoint          Normalized HTTP endpoint.
     * @param string $target_url        Direct endpoint URL.
     * @param string $host_header       WordPress virtual host.
     * @param int    $timeout_s         Timeout.
     * @param string $operation         Structured operation.
     * @param string $object_expression Optional obj.* BAN expression.
     * @param string $token             Shared HTTP control token.
     * @return array<string,mixed>
     */
    private static function send_varnish_http_contract_request(
        array $endpoint,
        $target_url,
        $host_header,
        $timeout_s,
        $operation,
        $object_expression = '',
        $token = ''
    ) {
        $started_at = microtime(true);
        $endpoint_label = self::normalize_varnish_metrics_endpoint_label($endpoint);
        $operation = sanitize_key((string) $operation);
        $raw_token = trim((string) $token);
        $token = self::sanitize_varnish_http_contract_token($raw_token);
        $object_expression = trim((string) $object_expression);
        $allowed_operations = array(
            'capabilities',
            'exact-purge',
            'exact-ban',
            'batch-ban',
            'html-ban',
            'host-ban',
        );
        if (!in_array($operation, $allowed_operations, true)) {
            return array(
                'ok' => false,
                'contractAvailable' => false,
                'status' => 'unsupported-operation',
                'detail' => self::sanitize_varnish_string('Unsupported UltraCache VCL contract operation.'),
                'code' => 0,
            );
        }
        if ('' === $token) {
            $token_status = '' === $raw_token ? 'token-not-configured' : 'token-invalid';
            return array(
                'ok' => false,
                'contractAvailable' => false,
                'status' => $token_status,
                'detail' => self::sanitize_varnish_string(
                    'token-invalid' === $token_status
                        ? 'The optional UltraCache VCL contract token must contain 32-128 ASCII letters, numbers, underscores, or hyphens.'
                        : 'The optional UltraCache VCL contract requires an HTTP token/control key.'
                ),
                'code' => 0,
            );
        }
        if ('' !== $object_expression) {
            if (strlen($object_expression) > 3000 || preg_match('/[\r\n\x00-\x1F\x7F]/', $object_expression)) {
                return array(
                    'ok' => false,
                    'contractAvailable' => true,
                    'status' => 'invalid-expression',
                    'detail' => self::sanitize_varnish_string('The UltraCache VCL object expression is invalid or oversized.'),
                    'code' => 0,
                );
            }
        }

        $headers = array(
            'Host' => strtolower(trim((string) $host_header)),
            'X-UltraCache-Contract' => (string) self::get_varnish_http_contract_version(),
            'X-UltraCache-Operation' => $operation,
            'X-UltraCache-Token' => $token,
        );
        if ('' !== $object_expression) {
            $headers['X-UltraCache-Object-Expression'] = $object_expression;
        }

        $response = ultracache_safe_configured_infrastructure_remote_request($target_url, array(
            'method' => 'PURGE',
            'timeout' => max(1, min(15, (int) $timeout_s)),
            'redirection' => 0,
            'headers' => $headers,
            'body' => '',
        ), 'configured_varnish_contract_request');

        if (is_wp_error($response)) {
            $result = array(
                'ok' => false,
                'contractAvailable' => false,
                'status' => 'request-error',
                'detail' => self::sanitize_varnish_string($response->get_error_message()),
                'code' => 0,
            );
            self::record_varnish_endpoint_result(
                $endpoint_label,
                'http-contract',
                false,
                (microtime(true) - $started_at) * 1000,
                (string) $result['detail']
            );
            return $result;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $message = trim((string) wp_remote_retrieve_response_message($response));
        $version = absint(self::get_varnish_response_header($response, 'x-ultracache-vcl-contract'));
        $adapter = sanitize_key(self::get_varnish_response_header($response, 'x-ultracache-vcl-adapter'));
        $result_header = sanitize_key(self::get_varnish_response_header($response, 'x-ultracache-vcl-result'));
        $response_operation = sanitize_key(self::get_varnish_response_header($response, 'x-ultracache-vcl-operation'));
        $auth = sanitize_key(self::get_varnish_response_header($response, 'x-ultracache-vcl-auth'));
        $capabilities = self::sanitize_varnish_http_contract_capabilities(
            self::get_varnish_response_header($response, 'x-ultracache-vcl-capabilities')
        );
        $contract_available = self::get_varnish_http_contract_version() === $version
            && self::get_varnish_http_contract_adapter() === $adapter;
        $authenticated = $contract_available && 'token' === $auth;
        $ok = $contract_available
            && $authenticated
            && $code >= 200
            && $code < 300
            && 'ok' === $result_header
            && $operation === $response_operation;

        if ($ok) {
            $status = 'available';
        } elseif ($contract_available && 501 === $code) {
            $status = 'contract-disabled';
        } elseif ($contract_available && 403 === $code) {
            $status = 'authentication-failed';
        } elseif ($contract_available && 400 === $code) {
            $status = 'operation-rejected';
        } elseif (!$contract_available && $code >= 200 && $code < 300) {
            $status = 'contract-not-advertised';
        } else {
            $status = $contract_available ? 'contract-failed' : 'contract-unavailable';
        }

        $detail = 'HTTP ' . $code . ('' !== $message ? ' ' . $message : '') . ' · ' . $status;
        $result = array(
            'ok' => $ok,
            'contractAvailable' => $contract_available,
            'authenticated' => $authenticated,
            'status' => $status,
            'detail' => self::sanitize_varnish_string($detail),
            'code' => $code,
            'version' => $version,
            'adapter' => $adapter,
            'operation' => $response_operation,
            'result' => $result_header,
            'capabilities' => $capabilities,
        );
        self::record_varnish_endpoint_result(
            $endpoint_label,
            'http-contract',
            $ok,
            (microtime(true) - $started_at) * 1000,
            (string) $result['detail']
        );

        return $result;
    }

    /**
     * Probe the optional versioned HTTP/VCL contract on one endpoint.
     *
     * @param string $endpoint_label Configured endpoint label.
     * @param array  $settings       Normalized settings.
     * @param int    $timeout        Timeout.
     * @return array<string,mixed>
     */
    private static function probe_varnish_http_contract($endpoint_label, array $settings, $timeout)
    {
        $endpoint_label = self::normalize_varnish_registry_endpoint($endpoint_label);
        $endpoint_check = self::validate_varnish_http_endpoint($endpoint_label);
        if (empty($endpoint_check['valid'])) {
            return array(
                'ok' => false,
                'contractAvailable' => false,
                'status' => 'invalid-endpoint',
                'detail' => self::sanitize_varnish_string((string) ($endpoint_check['message'] ?? 'Invalid Varnish endpoint.')),
                'capabilities' => array(),
            );
        }
        $endpoint = self::normalize_varnish_endpoint($endpoint_label);
        if (empty($endpoint)) {
            return array(
                'ok' => false,
                'contractAvailable' => false,
                'status' => 'invalid-endpoint',
                'detail' => self::sanitize_varnish_string('Invalid Varnish endpoint.'),
                'capabilities' => array(),
            );
        }
        $home = wp_parse_url(home_url('/'));
        $site_host = !empty($home['host']) ? strtolower((string) $home['host']) : (string) $endpoint['host'];
        $target_url = self::build_varnish_target_url($endpoint, '/__ultracache-vcl-contract-v2');

        return self::send_varnish_http_contract_request(
            $endpoint,
            $target_url,
            $site_host,
            $timeout,
            'capabilities',
            '',
            (string) ($settings['key'] ?? '')
        );
    }

    /**
     * Return one current authenticated contract profile for a configured endpoint.
     *
     * @param string $endpoint_label Endpoint label.
     * @param array  $settings       Normalized settings.
     * @return array<string,mixed>
     */
    private static function get_varnish_http_contract_runtime_profile($endpoint_label, array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }
        if ('http' !== self::sanitize_varnish_mode($settings['mode'] ?? 'http')) {
            return array();
        }
        $endpoint_label = self::normalize_varnish_registry_endpoint($endpoint_label);
        $key = self::get_varnish_registry_endpoint_key('http', $endpoint_label);
        if ('' === $key) {
            return array();
        }
        $profiles = self::get_current_varnish_endpoint_capability_profiles($settings);
        $profile = is_array($profiles[$key] ?? null) ? $profiles[$key] : array();
        if (
            self::get_varnish_http_contract_adapter() !== sanitize_key((string) ($profile['adapter'] ?? ''))
            || self::get_varnish_http_contract_version() !== absint($profile['contractVersion'] ?? 0)
            || empty($profile['contractAuthenticated'])
        ) {
            return array();
        }

        return $profile;
    }

    /**
     * Convert one portable BAN expression into the bounded object metadata contract.
     *
     * @param string $expression Portable BAN expression.
     * @return string
     */
    private static function build_varnish_object_metadata_expression($expression)
    {
        $expression = trim((string) $expression);
        if (
            '' === $expression
            || strlen($expression) > 3000
            || preg_match('/[\r\n\x00-\x1F\x7F]/', $expression)
            || preg_match('/\b(?:bereq|beresp|resp|client|server|storage)[.]/i', $expression)
        ) {
            return '';
        }

        $expression = preg_replace(
            array('/\breq[.]http[.]host\b/', '/\breq[.]url\b/'),
            array('obj.http.X-Cache-Object-Host', 'obj.http.X-Cache-Object-URL'),
            $expression
        );
        if (!is_string($expression) || preg_match('/\breq[.]/i', $expression)) {
            return '';
        }

        return $expression;
    }

    /**
     * Classify one object expression into the structured contract operation.
     *
     * @param string $expression Object-side BAN expression.
     * @return string
     */
    private static function classify_varnish_contract_ban_operation($expression)
    {
        $expression = (string) $expression;
        if (false !== strpos($expression, 'obj.http.Content-Type')) {
            return 'html-ban';
        }
        if (
            false === strpos($expression, 'obj.http.X-Cache-Object-URL')
            || preg_match('/obj[.]http[.]X-Cache-Object-URL\s*~\s*"(?:[.]\*|\^?[.]\*\$?)"/', $expression)
        ) {
            return 'host-ban';
        }
        if (false !== strpos($expression, '(?:') || false !== strpos($expression, '|')) {
            return 'batch-ban';
        }

        return 'exact-ban';
    }

    /**
     * Send one authenticated object-side BAN through the current contract.
     *
     * @param string $endpoint_label Configured endpoint label.
     * @param int    $timeout        Timeout.
     * @param string $expression     Portable BAN expression.
     * @param array  $settings       Normalized settings.
     * @param bool   $diagnostic_probe Whether to bypass behavior proof gates for this diagnostic command.
     * @return array<string,mixed>
     */
    private static function send_varnish_http_contract_ban($endpoint_label, $timeout, $expression, array $settings = array(), $diagnostic_probe = false)
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }
        $profile = self::get_varnish_http_contract_runtime_profile($endpoint_label, $settings);
        if ($diagnostic_probe) {
            $contract = self::probe_varnish_http_contract($endpoint_label, $settings, $timeout);
            if (!empty($contract['ok']) && !empty($contract['authenticated'])) {
                $profile = array(
                    'adapter' => sanitize_key((string) ($contract['adapter'] ?? '')),
                    'contractVersion' => absint($contract['version'] ?? 0),
                    'contractAuthenticated' => true,
                    'contractCapabilities' => self::sanitize_varnish_http_contract_capabilities($contract['capabilities'] ?? array()),
                );
            } else {
                return array(
                    'ok' => false,
                    'contractAvailable' => !empty($contract['contractAvailable']),
                    'status' => sanitize_key((string) ($contract['status'] ?? 'contract-unavailable')),
                    'detail' => self::sanitize_varnish_string((string) ($contract['detail'] ?? 'The versioned VCL contract is not available for this endpoint.')),
                    'code' => absint($contract['code'] ?? 0),
                );
            }
        }
        if (empty($profile)) {
            return array(
                'ok' => false,
                'contractAvailable' => false,
                'status' => 'contract-not-verified',
                'detail' => self::sanitize_varnish_string('The versioned VCL contract is not verified for this endpoint.'),
                'code' => 0,
            );
        }
        $object_expression = self::build_varnish_object_metadata_expression($expression);
        if ('' === $object_expression) {
            return array(
                'ok' => false,
                'contractAvailable' => true,
                'status' => 'invalid-expression',
                'detail' => self::sanitize_varnish_string('Could not convert the BAN expression into the bounded object metadata contract.'),
                'code' => 0,
            );
        }
        $operation = self::classify_varnish_contract_ban_operation($object_expression);
        $capability_map = array(
            'exact-ban' => 'exactBan',
            'batch-ban' => 'batchBan',
            'html-ban' => 'htmlFlush',
            'host-ban' => 'hostFlush',
        );
        $required = (string) ($capability_map[$operation] ?? '');
        $diagnostic_probe = (bool) $diagnostic_probe;
        $advertised_map = array(
            'exact-ban' => 'exact-ban',
            'batch-ban' => 'batch-ban',
            'html-ban' => 'html-ban',
            'host-ban' => 'host-ban',
        );
        $advertised_capability = (string) ($advertised_map[$operation] ?? '');
        $diagnostic_allowed = $diagnostic_probe
            && '' !== $advertised_capability
            && in_array($advertised_capability, (array) ($profile['contractCapabilities'] ?? array()), true);
        if ('' === $required || (!$diagnostic_allowed && !self::is_varnish_endpoint_capability_current($profile, $required))) {
            return array(
                'ok' => false,
                'contractAvailable' => true,
                'status' => 'capability-not-verified',
                'detail' => self::sanitize_varnish_string($diagnostic_probe
                    ? 'The authenticated endpoint contract does not advertise ' . $operation . '.'
                    : 'The endpoint contract does not have a current proof for ' . $operation . '.'),
                'code' => 0,
            );
        }

        $endpoint_check = self::validate_varnish_http_endpoint($endpoint_label);
        $endpoint = !empty($endpoint_check['valid']) ? self::normalize_varnish_endpoint($endpoint_label) : array();
        if (empty($endpoint)) {
            return array(
                'ok' => false,
                'contractAvailable' => true,
                'status' => 'invalid-endpoint',
                'detail' => self::sanitize_varnish_string((string) ($endpoint_check['message'] ?? 'Invalid Varnish endpoint.')),
                'code' => 0,
            );
        }
        $home = wp_parse_url(home_url('/'));
        $site_host = !empty($home['host']) ? strtolower((string) $home['host']) : (string) $endpoint['host'];
        $target_url = self::build_varnish_target_url($endpoint, '/__ultracache-vcl-contract-v2');

        return self::send_varnish_http_contract_request(
            $endpoint,
            $target_url,
            $site_host,
            $timeout,
            $operation,
            $object_expression,
            (string) ($settings['key'] ?? '')
        );
    }

    /**
     * Send one exact PURGE through a probed contract, including canary runs.
     *
     * @param string $endpoint_label Configured endpoint label.
     * @param string $url            Same-origin URL.
     * @param int    $timeout        Timeout.
     * @param array  $settings         Normalized settings.
     * @param bool   $diagnostic_probe Whether this is a scoped pre-proof diagnostic operation.
     * @return array<string,mixed>
     */
    private static function send_varnish_http_contract_exact_purge($endpoint_label, $url, $timeout, array $settings = array(), $diagnostic_probe = false)
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }
        if ($diagnostic_probe
            && (!method_exists(static::class, 'is_varnish_capability_probe_authorized')
                || !self::is_varnish_capability_probe_authorized('targeted', array(
                    'strategy' => 'exact-purge',
                    'requestedScope' => 'exact-url',
                    'endpoints' => array($endpoint_label),
                    'urls' => array($url),
                )))) {
            return array(
                'ok' => false,
                'contractAvailable' => true,
                'status' => 'diagnostic-probe-not-authorized',
                'detail' => self::sanitize_varnish_string('The exact-PURGE diagnostic operation is outside the active scoped capability probe.'),
                'code' => 0,
            );
        }
        $endpoint_check = self::validate_varnish_http_endpoint($endpoint_label);
        $endpoint = !empty($endpoint_check['valid']) ? self::normalize_varnish_endpoint($endpoint_label) : array();
        if (empty($endpoint)) {
            return array(
                'ok' => false,
                'contractAvailable' => true,
                'status' => 'invalid-endpoint',
                'detail' => self::sanitize_varnish_string((string) ($endpoint_check['message'] ?? 'Invalid Varnish endpoint.')),
                'code' => 0,
            );
        }
        $parts = wp_parse_url((string) $url);
        $path = is_array($parts) && !empty($parts['path']) ? (string) $parts['path'] : '/';
        if (is_array($parts) && !empty($parts['query'])) {
            $path .= '?' . (string) $parts['query'];
        }
        $site_host = is_array($parts) && !empty($parts['host'])
            ? strtolower(rtrim((string) $parts['host'], '.'))
            : (string) $endpoint['host'];
        $public_scheme = is_array($parts) && !empty($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
        $public_port = is_array($parts) ? absint($parts['port'] ?? 0) : 0;
        $default_port = 'https' === $public_scheme ? 443 : 80;
        if ($public_port > 0 && $public_port !== $default_port) {
            $site_host .= ':' . $public_port;
        }
        $target_url = self::build_varnish_target_url($endpoint, $path);

        return self::send_varnish_http_contract_request(
            $endpoint,
            $target_url,
            $site_host,
            $timeout,
            'exact-purge',
            '',
            (string) ($settings['key'] ?? '')
        );
    }

    /**
     * Clear a stale contract adapter after a semantic contract failure.
     *
     * @param string $endpoint_label Endpoint label.
     * @param array  $result         Contract response.
     * @param array  $settings       Normalized settings.
     * @return void
     */
    private static function maybe_downgrade_varnish_http_contract_profile($endpoint_label, array $result, array $settings = array())
    {
        $status = sanitize_key((string) ($result['status'] ?? ''));
        if (!in_array($status, array('contract-disabled', 'authentication-failed', 'contract-not-advertised', 'contract-unavailable'), true)) {
            return;
        }
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }
        self::update_varnish_endpoint_capability_profile(
            'http',
            $endpoint_label,
            array(
                'adapter' => 'http-unverified',
                'reachable' => !empty($result['code']),
                'exactPurge' => false,
                'exactBan' => false,
                'batchBan' => false,
                'htmlFlush' => false,
                'hostFlush' => false,
                'softPurge' => false,
                'originRevalidation' => false,
                'swr' => false,
                'exactRuntimeAvailable' => false,
                'topologyRuntimeAvailable' => false,
                'softPurgeRuntimeAvailable' => false,
                'contractAuthenticated' => false,
                'contractId' => '',
                'contractCapabilities' => array(),
                'contractReportedAt' => time(),
                'contractVersion' => 1,
                'proofExpiresAt' => 0,
                'exactProofExpiresAt' => 0,
                'topologyProofExpiresAt' => 0,
                'softPurgeProofExpiresAt' => 0,
                'source' => 'contract-downgrade',
                'status' => $status,
                'lastFailureAt' => time(),
                'lastFailure' => self::sanitize_varnish_string((string) ($result['detail'] ?? 'The VCL contract is no longer available.')),
            ),
            $settings
        );
    }
}
