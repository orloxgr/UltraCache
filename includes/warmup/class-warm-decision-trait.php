<?php
/**
 * Atomic warm-up ownership decision and lease lifecycle.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Warm_Decision_Trait
{
    /**
     * Return the single authoritative warm decision state name.
     *
     * @return string
     */
    private static function get_warm_decision_state_name()
    {
        return 'ultracache_state:warm_decision';
    }

    /**
     * Return the default authoritative warm decision payload.
     *
     * @return array<string,mixed>
     */
    private static function get_default_warm_decision_state()
    {
        return array(
            'decisionGeneration' => 0,
            'foregroundSource' => '',
            'foregroundToken' => '',
            'foregroundGeneration' => 0,
            'foregroundStatus' => 'idle',
            'foregroundJobType' => '',
            'foregroundRequestedAt' => 0,
            'foregroundStartedAt' => 0,
            'foregroundHeartbeatAt' => 0,
            'foregroundPausedAt' => 0,
            'foregroundLeaseExpiresAt' => 0,
            'foregroundCurrentUrl' => '',
            'foregroundCurrentStage' => '',
            'cronToken' => '',
            'cronGeneration' => 0,
            'cronStatus' => 'idle',
            'cronStartedAt' => 0,
            'cronHeartbeatAt' => 0,
            'cronLeaseExpiresAt' => 0,
            'cronCurrentUrl' => '',
            'cronCurrentStage' => '',
            'lastDecisionReason' => '',
        );
    }

    /**
     * Normalize the complete authoritative warm decision payload.
     *
     * @param array<string,mixed> $state Decision payload.
     * @return array<string,mixed>
     */
    private static function normalize_warm_decision_state(array $state)
    {
        $state = array_merge(self::get_default_warm_decision_state(), $state);

        $foreground_status = sanitize_key((string) $state['foregroundStatus']);
        if (!in_array($foreground_status, array('idle', 'running', 'paused', 'cancelled', 'completed', 'interrupted', 'expired'), true)) {
            $foreground_status = 'idle';
        }

        $cron_status = sanitize_key((string) $state['cronStatus']);
        if (!in_array($cron_status, array('idle', 'running', 'released', 'expired'), true)) {
            $cron_status = 'idle';
        }

        return array(
            'decisionGeneration' => max(0, (int) $state['decisionGeneration']),
            'foregroundSource' => self::normalize_foreground_warm_source($state['foregroundSource']),
            'foregroundToken' => sanitize_text_field((string) $state['foregroundToken']),
            'foregroundGeneration' => max(0, (int) $state['foregroundGeneration']),
            'foregroundStatus' => $foreground_status,
            'foregroundJobType' => self::sanitize_manual_warm_job_type($state['foregroundJobType']),
            'foregroundRequestedAt' => max(0, (int) $state['foregroundRequestedAt']),
            'foregroundStartedAt' => max(0, (int) $state['foregroundStartedAt']),
            'foregroundHeartbeatAt' => max(0, (int) $state['foregroundHeartbeatAt']),
            'foregroundPausedAt' => max(0, (int) $state['foregroundPausedAt']),
            'foregroundLeaseExpiresAt' => max(0, (int) $state['foregroundLeaseExpiresAt']),
            'foregroundCurrentUrl' => esc_url_raw((string) $state['foregroundCurrentUrl']),
            'foregroundCurrentStage' => sanitize_key((string) $state['foregroundCurrentStage']),
            'cronToken' => sanitize_text_field((string) $state['cronToken']),
            'cronGeneration' => max(0, (int) $state['cronGeneration']),
            'cronStatus' => $cron_status,
            'cronStartedAt' => max(0, (int) $state['cronStartedAt']),
            'cronHeartbeatAt' => max(0, (int) $state['cronHeartbeatAt']),
            'cronLeaseExpiresAt' => max(0, (int) $state['cronLeaseExpiresAt']),
            'cronCurrentUrl' => esc_url_raw((string) $state['cronCurrentUrl']),
            'cronCurrentStage' => sanitize_key((string) $state['cronCurrentStage']),
            'lastDecisionReason' => sanitize_key((string) $state['lastDecisionReason']),
        );
    }

    /**
     * Apply lease expiry to a decision payload.
     *
     * @param array<string,mixed> $state Decision payload.
     * @param int                 $now   Current timestamp.
     * @return array{state:array<string,mixed>,foregroundExpired:bool,cronExpired:bool}
     */
    private static function expire_warm_decision_leases(array $state, $now)
    {
        $state = self::normalize_warm_decision_state($state);
        $now = max(1, (int) $now);
        $foreground_expired = false;
        $cron_expired = false;

        if (
            'running' === $state['foregroundStatus']
            && (
                '' === $state['foregroundSource']
                || '' === $state['foregroundToken']
                || $state['foregroundLeaseExpiresAt'] <= $now
            )
        ) {
            $state['foregroundStatus'] = 'expired';
            $state['foregroundToken'] = '';
            $state['foregroundLeaseExpiresAt'] = 0;
            $state['foregroundCurrentUrl'] = '';
            $state['foregroundCurrentStage'] = '';
            $state['decisionGeneration']++;
            $state['lastDecisionReason'] = 'foreground_lease_expired';
            $foreground_expired = true;
        }

        if (
            'running' === $state['cronStatus']
            && (
                '' === $state['cronToken']
                || $state['cronLeaseExpiresAt'] <= $now
            )
        ) {
            $state['cronStatus'] = 'expired';
            $state['cronToken'] = '';
            $state['cronLeaseExpiresAt'] = 0;
            $state['cronCurrentUrl'] = '';
            $state['cronCurrentStage'] = '';
            $state['decisionGeneration']++;
            $state['lastDecisionReason'] = 'cron_lease_expired';
            $cron_expired = true;
        }

        return array(
            'state' => $state,
            'foregroundExpired' => $foreground_expired,
            'cronExpired' => $cron_expired,
        );
    }

    /**
     * Commit newly expired leases exactly once through CAS.
     *
     * @return array{foregroundExpired:bool,cronExpired:bool}
     */
    private static function commit_expired_warm_decision_leases()
    {
        $foreground_expired = false;
        $cron_expired = false;
        if (!function_exists('ultracache_mutate_state_record')) {
            return array('foregroundExpired' => false, 'cronExpired' => false);
        }

        $mutation = ultracache_mutate_state_record(
            self::get_warm_decision_state_name(),
            static function ($payload) use (&$foreground_expired, &$cron_expired) {
                $expired = self::expire_warm_decision_leases((array) $payload, time());
                if (empty($expired['foregroundExpired']) && empty($expired['cronExpired'])) {
                    return null;
                }
                $foreground_expired = !empty($expired['foregroundExpired']);
                $cron_expired = !empty($expired['cronExpired']);
                return $expired['state'];
            },
            5,
            self::get_default_warm_decision_state()
        );

        if (empty($mutation['success'])) {
            $foreground_expired = false;
            $cron_expired = false;
        }
        if ($foreground_expired) {
            self::resume_background_automation_after_foreground_warm();
        }

        return array(
            'foregroundExpired' => $foreground_expired,
            'cronExpired' => $cron_expired,
        );
    }

    /**
     * Read the authoritative decision payload, optionally committing lease expiry.
     *
     * @param bool $recover_expired Whether expired leases may be committed.
     * @return array<string,mixed>
     */
    private static function get_warm_decision_state($recover_expired = false)
    {
        if (!function_exists('ultracache_get_state_record') || !function_exists('ultracache_create_state_record')) {
            return self::get_default_warm_decision_state();
        }

        $state_name = self::get_warm_decision_state_name();
        $record = (!$recover_expired && function_exists('ultracache_get_state_record_read_only'))
            ? ultracache_get_state_record_read_only($state_name)
            : ultracache_get_state_record($state_name);
        if (empty($record)) {
            return self::get_default_warm_decision_state();
        }

        $state = self::normalize_warm_decision_state((array) ($record['payload'] ?? array()));
        $expiry = self::expire_warm_decision_leases($state, time());
        if (empty($expiry['foregroundExpired']) && empty($expiry['cronExpired'])) {
            return $state;
        }

        if (!$recover_expired) {
            return $expiry['state'];
        }

        self::commit_expired_warm_decision_leases();
        $record = ultracache_get_state_record($state_name);
        return !empty($record)
            ? self::normalize_warm_decision_state((array) ($record['payload'] ?? array()))
            : $expiry['state'];
    }

    /**
     * Execute one bounded atomic warm decision mutation.
     *
     * @param callable $mutator Decision payload mutator.
     * @return array<string,mixed>
     */
    private static function mutate_warm_decision_state(callable $mutator)
    {
        if (!function_exists('ultracache_mutate_state_record')) {
            return array(
                'success' => false,
                'reason' => 'storage_unavailable',
                'state' => array(),
            );
        }

        return ultracache_mutate_state_record(
            self::get_warm_decision_state_name(),
            static function ($payload, $record, $attempt) use ($mutator) {
                $state = self::normalize_warm_decision_state((array) $payload);
                $expiry = self::expire_warm_decision_leases($state, time());
                return call_user_func($mutator, $expiry['state'], $record, $attempt);
            },
            5,
            self::get_default_warm_decision_state()
        );
    }

    /**
     * Return whether a valid foreground owner currently exists.
     *
     * @param array<string,mixed> $state Decision payload.
     * @param int                 $now   Current timestamp.
     * @return bool
     */
    private static function has_valid_foreground_warm_decision(array $state, $now = 0)
    {
        $state = self::normalize_warm_decision_state($state);
        $now = $now > 0 ? (int) $now : time();
        return 'running' === $state['foregroundStatus']
            && '' !== $state['foregroundSource']
            && '' !== $state['foregroundToken']
            && $state['foregroundLeaseExpiresAt'] > $now;
    }

    /**
     * Return whether a valid cron owner currently exists.
     *
     * @param array<string,mixed> $state Decision payload.
     * @param int                 $now   Current timestamp.
     * @return bool
     */
    private static function has_valid_cron_warm_decision(array $state, $now = 0)
    {
        $state = self::normalize_warm_decision_state($state);
        $now = $now > 0 ? (int) $now : time();
        return !self::has_valid_foreground_warm_decision($state, $now)
            && 'running' === $state['cronStatus']
            && '' !== $state['cronToken']
            && $state['cronLeaseExpiresAt'] > $now;
    }

    /**
     * Validate an exact execution fence against the authoritative decision.
     *
     * A fence identifies one committed UI, WP-CLI, or cron generation. The
     * token prevents unrelated owners from matching, while the generation
     * prevents an older worker from continuing after an explicit resume or
     * replacement decision.
     *
     * @param array<string,mixed> $fence Execution fence descriptor.
     * @return bool
     */
    private static function is_warm_execution_fence_current(array $fence)
    {
        $source = sanitize_key((string) ($fence['source'] ?? ''));
        $token = sanitize_text_field((string) ($fence['token'] ?? ''));
        $generation = max(0, (int) ($fence['generation'] ?? 0));
        if ('' === $token || $generation < 1 || !in_array($source, array('ui', 'cli', 'cron'), true)) {
            return false;
        }

        $state = self::get_warm_decision_state(false);
        if ('cron' === $source) {
            return self::has_valid_cron_warm_decision($state)
                && hash_equals((string) $state['cronToken'], $token)
                && $generation === (int) $state['cronGeneration'];
        }

        return self::has_valid_foreground_warm_decision($state)
            && $source === (string) $state['foregroundSource']
            && hash_equals((string) $state['foregroundToken'], $token)
            && $generation === (int) $state['foregroundGeneration'];
    }

    /**
     * Return a public projection of the authoritative decision.
     *
     * @param bool $recover_expired Whether lease expiry may be committed.
     * @return array<string,mixed>
     */
    private static function get_warm_decision_status($recover_expired = false)
    {
        $state = self::get_warm_decision_state($recover_expired);
        $foreground_active = self::has_valid_foreground_warm_decision($state);
        $cron_active = self::has_valid_cron_warm_decision($state);
        $owner_source = $foreground_active
            ? (string) $state['foregroundSource']
            : ($cron_active ? 'cron' : '');

        return array(
            'ownerSource' => $owner_source,
            'decisionGeneration' => max(0, (int) $state['decisionGeneration']),
            'lastDecisionReason' => (string) $state['lastDecisionReason'],
            'foreground' => array(
                'status' => (string) $state['foregroundStatus'],
                'source' => (string) $state['foregroundSource'],
                'active' => $foreground_active,
                'jobType' => (string) $state['foregroundJobType'],
                'generation' => max(0, (int) $state['foregroundGeneration']),
                'requestedAt' => max(0, (int) $state['foregroundRequestedAt']),
                'startedAt' => max(0, (int) $state['foregroundStartedAt']),
                'heartbeatAt' => max(0, (int) $state['foregroundHeartbeatAt']),
                'pausedAt' => max(0, (int) $state['foregroundPausedAt']),
                'leaseExpiresAt' => max(0, (int) $state['foregroundLeaseExpiresAt']),
                'currentUrl' => (string) $state['foregroundCurrentUrl'],
                'currentStage' => (string) $state['foregroundCurrentStage'],
            ),
            'cron' => array(
                'status' => (string) $state['cronStatus'],
                'active' => $cron_active,
                'generation' => max(0, (int) $state['cronGeneration']),
                'startedAt' => max(0, (int) $state['cronStartedAt']),
                'heartbeatAt' => max(0, (int) $state['cronHeartbeatAt']),
                'leaseExpiresAt' => max(0, (int) $state['cronLeaseExpiresAt']),
                'currentUrl' => (string) $state['cronCurrentUrl'],
                'currentStage' => (string) $state['cronCurrentStage'],
            ),
        );
    }

    /**
     * Sanitize a foreground warm job type.
     *
     * @param mixed $job_type Job type.
     * @return string
     */
    private static function sanitize_manual_warm_job_type($job_type)
    {
        $job_type = sanitize_key((string) $job_type);
        $allowed = array(
            'warm',
            'warm_menu',
            'warm_css',
            'warm_menu_css',
            'warm_css_homepage',
            'warm_menu_css_homepage',
            'warm_css_shared',
            'warm_menu_css_shared',
            'warm_css_per_page',
            'warm_menu_css_per_page',
            'runtime_scan',
            'cli_warm',
        );

        return in_array($job_type, $allowed, true) ? $job_type : '';
    }

    /**
     * Begin or explicitly resume a foreground session.
     *
     * Every successful start/resume receives the newest committed decision
     * generation. UI and WP-CLI are therefore equal-priority owners and the
     * latest successful CAS commit supersedes every older foreground session.
     *
     * @param string $source          ui or cli.
     * @param string $job_type        Warm job type.
     * @param string $preferred_token Existing resumable token.
     * @return array<string,mixed>
     */
    public static function begin_foreground_warmup_session($source, $job_type, $preferred_token = '')
    {
        $source = self::normalize_foreground_warm_source($source);
        $job_type = self::sanitize_manual_warm_job_type($job_type);
        if ('' === $source || '' === $job_type) {
            return array('success' => false, 'message' => self::maybe_translate('Invalid foreground warm-up session.'), 'state' => self::get_manual_warm_status());
        }

        $preferred_token = sanitize_text_field((string) $preferred_token);
        $generated_token = wp_generate_password(32, false, false);
        $selected_token = '';
        $selected_generation = 0;
        $resumed = false;
        $now = time();

        $mutation = self::mutate_warm_decision_state(
            static function ($state) use ($source, $job_type, $preferred_token, $generated_token, $now, &$selected_token, &$selected_generation, &$resumed) {
                $same_token = '' !== $preferred_token
                    && '' !== $state['foregroundToken']
                    && hash_equals((string) $state['foregroundToken'], $preferred_token);
                $same_source = $source === (string) $state['foregroundSource'];
                $resumed = $same_token
                    && $same_source
                    && in_array((string) $state['foregroundStatus'], array('running', 'paused'), true);
                // Every explicit start or resume receives a fresh token. This
                // keeps an older in-flight request fenced even when a paused
                // session is resumed from the same persisted dashboard job.
                $selected_token = $generated_token;
                $selected_generation = max(
                    (int) $state['decisionGeneration'],
                    (int) $state['foregroundGeneration'],
                    (int) $state['cronGeneration']
                ) + 1;

                $state['decisionGeneration'] = $selected_generation;
                $state['foregroundSource'] = $source;
                $state['foregroundToken'] = $selected_token;
                $state['foregroundGeneration'] = $selected_generation;
                $state['foregroundStatus'] = 'running';
                $state['foregroundJobType'] = $job_type;
                $state['foregroundRequestedAt'] = $now;
                $state['foregroundStartedAt'] = $resumed && $state['foregroundStartedAt'] > 0
                    ? (int) $state['foregroundStartedAt']
                    : $now;
                $state['foregroundHeartbeatAt'] = $now;
                $state['foregroundPausedAt'] = 0;
                $state['foregroundLeaseExpiresAt'] = $now + self::get_manual_warm_session_lease_seconds();
                $state['foregroundCurrentUrl'] = '';
                $state['foregroundCurrentStage'] = '';
                $state['cronToken'] = '';
                $state['cronStatus'] = 'released';
                $state['cronHeartbeatAt'] = 0;
                $state['cronLeaseExpiresAt'] = 0;
                $state['cronCurrentUrl'] = '';
                $state['cronCurrentStage'] = '';
                $state['lastDecisionReason'] = $resumed
                    ? 'foreground_' . $source . '_resumed'
                    : 'foreground_' . $source . '_started';
                return $state;
            }
        );

        if (empty($mutation['success'])) {
            return array(
                'success' => false,
                'message' => self::maybe_translate('Foreground warm-up ownership could not be acquired.'),
                'state' => self::get_manual_warm_status(),
            );
        }

        self::yield_cron_warmup_to_foreground($source);

        return array(
            'success' => true,
            'message' => 'cli' === $source
                ? self::maybe_translate('WP-CLI warm-up acquired foreground priority.')
                : self::maybe_translate('Dashboard warm-up acquired foreground priority.'),
            'token' => $selected_token,
            'generation' => $selected_generation,
            'resumed' => $resumed,
            'state' => self::get_manual_warm_status(),
            'cronWarm' => self::get_cron_warm_status(),
        );
    }

    /**
     * Begin or resume a dashboard foreground session.
     *
     * @param string $job_type        Warm job type.
     * @param string $preferred_token Existing resumable token.
     * @return array<string,mixed>
     */
    public static function begin_manual_warmup_session($job_type, $preferred_token = '')
    {
        return self::begin_foreground_warmup_session('ui', $job_type, $preferred_token);
    }

    /**
     * Renew the authoritative foreground lease.
     *
     * @param string $token  Foreground token.
     * @param string $source Optional source assertion.
     * @param string $stage  Current stage.
     * @param string $url                 Current URL.
     * @param int    $expected_generation Exact generation expected by the worker.
     * @return array<string,mixed>
     */
    public static function renew_foreground_warmup_session($token, $source = '', $stage = '', $url = '', $expected_generation = 0)
    {
        $token = sanitize_text_field((string) $token);
        $source = self::normalize_foreground_warm_source($source);
        $stage = sanitize_key((string) $stage);
        $url = esc_url_raw((string) $url);
        $expected_generation = max(0, (int) $expected_generation);
        $rejected = '';
        $matched_generation = 0;
        $now = time();

        $mutation = self::mutate_warm_decision_state(
            static function ($state) use ($token, $source, $stage, $url, $expected_generation, $now, &$rejected, &$matched_generation) {
                if (
                    '' === $token
                    || '' === $state['foregroundToken']
                    || !hash_equals((string) $state['foregroundToken'], $token)
                    || 'running' !== (string) $state['foregroundStatus']
                    || ('' !== $source && $source !== (string) $state['foregroundSource'])
                    || ($expected_generation > 0 && $expected_generation !== (int) $state['foregroundGeneration'])
                ) {
                    $rejected = 'ownership_mismatch';
                    return null;
                }

                $matched_generation = max(0, (int) $state['foregroundGeneration']);
                $state['foregroundHeartbeatAt'] = $now;
                $state['foregroundLeaseExpiresAt'] = $now + self::get_manual_warm_session_lease_seconds();
                $state['foregroundCurrentStage'] = $stage;
                $state['foregroundCurrentUrl'] = $url;
                $state['lastDecisionReason'] = 'foreground_heartbeat';
                return $state;
            }
        );

        if (empty($mutation['success'])) {
            return array('success' => false, 'message' => self::maybe_translate('Foreground warm-up ownership could not be verified.'), 'reason' => $rejected ?: (string) ($mutation['reason'] ?? ''), 'state' => self::get_manual_warm_status());
        }

        return array(
            'success' => true,
            'token' => $token,
            'generation' => $matched_generation,
            'state' => self::get_manual_warm_status(),
        );
    }

    /**
     * Renew a dashboard foreground lease.
     *
     * @param string $token Foreground token.
     * @return array<string,mixed>
     */
    public static function renew_manual_warmup_session($token)
    {
        return self::renew_foreground_warmup_session($token, 'ui');
    }

    /**
     * Pause a foreground session and release execution priority.
     *
     * @param string $token  Foreground token.
     * @param string $source Optional source assertion.
     * @return array<string,mixed>
     */
    public static function pause_foreground_warmup_session($token, $source = '')
    {
        $token = sanitize_text_field((string) $token);
        $source = self::normalize_foreground_warm_source($source);
        $rejected = '';
        $paused_source = '';
        $now = time();
        $mutation = self::mutate_warm_decision_state(
            static function ($state) use ($token, $source, $now, &$rejected, &$paused_source) {
                if (
                    '' === $token
                    || '' === $state['foregroundToken']
                    || !hash_equals((string) $state['foregroundToken'], $token)
                    || 'running' !== (string) $state['foregroundStatus']
                    || ('' !== $source && $source !== (string) $state['foregroundSource'])
                ) {
                    $rejected = 'ownership_mismatch';
                    return null;
                }

                $paused_source = (string) $state['foregroundSource'];
                $state['foregroundStatus'] = 'paused';
                $state['foregroundHeartbeatAt'] = $now;
                $state['foregroundPausedAt'] = $now;
                $state['foregroundLeaseExpiresAt'] = 0;
                $state['foregroundCurrentUrl'] = '';
                $state['foregroundCurrentStage'] = '';
                $state['decisionGeneration']++;
                $state['lastDecisionReason'] = 'foreground_' . $paused_source . '_paused';
                return $state;
            }
        );

        if (empty($mutation['success'])) {
            return array('success' => false, 'message' => self::maybe_translate('Foreground warm-up ownership could not be verified.'), 'reason' => $rejected ?: (string) ($mutation['reason'] ?? ''), 'state' => self::get_manual_warm_status());
        }

        self::resume_background_automation_after_foreground_warm();
        return array(
            'success' => true,
            'message' => self::maybe_translate('Foreground warm-up paused. Background automation may continue.'),
            'token' => $token,
            'source' => $paused_source,
            'state' => self::get_manual_warm_status(),
            'cronWarm' => self::get_cron_warm_status(),
        );
    }

    /**
     * Pause a dashboard foreground session.
     *
     * @param string $token Foreground token.
     * @return array<string,mixed>
     */
    public static function pause_manual_warmup_session($token)
    {
        $result = self::pause_foreground_warmup_session($token, 'ui');
        if (!empty($result['success'])) {
            $result['message'] = self::maybe_translate('Manual warm-up paused. Background automation may continue.');
        }
        return $result;
    }

    /**
     * Cancel a dashboard foreground session.
     *
     * @param string $token Foreground token.
     * @return array<string,mixed>
     */
    public static function cancel_manual_warmup_session($token)
    {
        $result = self::end_foreground_warmup_session($token, 'ui', 'cancelled');
        if (!empty($result['success'])) {
            $result['message'] = self::maybe_translate('Manual warm-up cancelled. Background automation resumed.');
        }
        return $result;
    }

    /**
     * End one authoritative foreground session.
     *
     * @param string $token  Foreground token.
     * @param string $source Optional source assertion.
     * @param string $status Terminal status.
     * @return array<string,mixed>
     */
    public static function end_foreground_warmup_session($token, $source = '', $status = 'completed')
    {
        $token = sanitize_text_field((string) $token);
        $source = self::normalize_foreground_warm_source($source);
        $status = sanitize_key((string) $status);
        if (!in_array($status, array('completed', 'cancelled', 'interrupted'), true)) {
            $status = 'completed';
        }
        $rejected = '';
        $now = time();

        $mutation = self::mutate_warm_decision_state(
            static function ($state) use ($token, $source, $status, $now, &$rejected) {
                if (
                    '' === $token
                    || '' === $state['foregroundToken']
                    || !hash_equals((string) $state['foregroundToken'], $token)
                    || ('' !== $source && $source !== (string) $state['foregroundSource'])
                ) {
                    $rejected = 'ownership_mismatch';
                    return null;
                }

                $state['foregroundStatus'] = $status;
                $state['foregroundToken'] = '';
                $state['foregroundHeartbeatAt'] = $now;
                $state['foregroundLeaseExpiresAt'] = 0;
                $state['foregroundCurrentUrl'] = '';
                $state['foregroundCurrentStage'] = '';
                $state['decisionGeneration']++;
                $state['lastDecisionReason'] = 'foreground_' . $status;
                return $state;
            }
        );

        if (empty($mutation['success'])) {
            return array('success' => false, 'message' => self::maybe_translate('Foreground warm-up ownership could not be verified.'), 'reason' => $rejected ?: (string) ($mutation['reason'] ?? ''), 'state' => self::get_manual_warm_status());
        }

        self::resume_background_automation_after_foreground_warm();
        return array(
            'success' => true,
            'message' => self::maybe_translate('Foreground warm-up ownership released.'),
            'state' => self::get_manual_warm_status(),
            'cronWarm' => self::get_cron_warm_status(),
        );
    }

    /**
     * Complete a dashboard foreground session.
     *
     * @param string $token Foreground token.
     * @return array<string,mixed>
     */
    public static function end_manual_warmup_session($token)
    {
        return self::end_foreground_warmup_session($token, 'ui', 'completed');
    }

    /**
     * Reset foreground intent without consulting legacy ownership options.
     *
     * @param string $reason Reset reason.
     * @return array<string,mixed>
     */
    public static function reset_manual_warmup_session($reason = 'reset')
    {
        $reason = sanitize_key((string) $reason);
        self::mutate_warm_decision_state(
            static function ($state) use ($reason) {
                $state['foregroundStatus'] = 'cancelled';
                $state['foregroundToken'] = '';
                $state['foregroundHeartbeatAt'] = time();
                $state['foregroundLeaseExpiresAt'] = 0;
                $state['foregroundCurrentUrl'] = '';
                $state['foregroundCurrentStage'] = '';
                $state['decisionGeneration']++;
                $state['lastDecisionReason'] = '' !== $reason ? $reason : 'foreground_reset';
                return $state;
            }
        );
        self::resume_background_automation_after_foreground_warm();
        return self::get_manual_warm_status();
    }

    /**
     * Return the foreground compatibility projection from warm_decision.
     *
     * @param bool $recover_expired Whether expired ownership may be committed.
     * @return array<string,mixed>
     */
    private static function get_manual_warm_state($recover_expired = true)
    {
        $state = self::get_warm_decision_state($recover_expired);
        $status = (string) $state['foregroundStatus'];
        return array(
            'status' => $status,
            'source' => (string) $state['foregroundSource'],
            'active' => self::has_valid_foreground_warm_decision($state),
            'paused' => 'paused' === $status,
            'interrupted' => in_array($status, array('interrupted', 'expired'), true),
            'jobType' => (string) $state['foregroundJobType'],
            'token' => (string) $state['foregroundToken'],
            'ownerUserId' => 0,
            'generation' => max(0, (int) $state['foregroundGeneration']),
            'startedAt' => max(0, (int) $state['foregroundStartedAt']),
            'updatedAt' => max(0, (int) $state['foregroundHeartbeatAt']),
            'heartbeatAt' => max(0, (int) $state['foregroundHeartbeatAt']),
            'pausedAt' => max(0, (int) $state['foregroundPausedAt']),
            'leaseExpiresAt' => max(0, (int) $state['foregroundLeaseExpiresAt']),
            'currentUrl' => (string) $state['foregroundCurrentUrl'],
            'currentStage' => (string) $state['foregroundCurrentStage'],
        );
    }

    /**
     * Return the current foreground status projection.
     *
     * @return array<string,mixed>
     */
    public static function get_manual_warm_status()
    {
        $state = self::get_manual_warm_state(false);
        return array(
            'status' => (string) $state['status'],
            'source' => (string) $state['source'],
            'active' => !empty($state['active']),
            'paused' => !empty($state['paused']),
            'interrupted' => !empty($state['interrupted']),
            'jobType' => (string) $state['jobType'],
            'generation' => max(0, (int) $state['generation']),
            'startedAt' => max(0, (int) $state['startedAt']),
            'updatedAt' => max(0, (int) $state['updatedAt']),
            'heartbeatAt' => max(0, (int) $state['heartbeatAt']),
            'pausedAt' => max(0, (int) $state['pausedAt']),
            'leaseExpiresAt' => max(0, (int) $state['leaseExpiresAt']),
            'currentUrl' => esc_url_raw((string) $state['currentUrl']),
            'currentStage' => sanitize_key((string) $state['currentStage']),
        );
    }

    /**
     * Determine whether authoritative foreground ownership blocks cron.
     *
     * @param bool $recover_expired Whether expired ownership may be recovered.
     * @return bool
     */
    public static function is_manual_warmup_blocking_cron($recover_expired = true)
    {
        return self::has_valid_foreground_warm_decision(self::get_warm_decision_state($recover_expired));
    }

    /**
     * Atomically acquire or renew cron ownership when no foreground owner exists.
     *
     * @param string   $token               Cron token.
     * @param int      $ttl                 Lease duration.
     * @param int|null $selected_generation Acquired generation.
     * @return bool
     */
    private static function acquire_cron_warm_decision($token, $ttl, &$selected_generation = null)
    {
        $token = sanitize_text_field((string) $token);
        $ttl = max(10, (int) $ttl);
        if ('' === $token) {
            return false;
        }

        $rejected = '';
        $selected_generation = 0;
        $now = time();
        $mutation = self::mutate_warm_decision_state(
            static function ($state) use ($token, $ttl, $now, &$rejected, &$selected_generation) {
                if (self::has_valid_foreground_warm_decision($state, $now)) {
                    $rejected = 'foreground_active';
                    return null;
                }
                if (
                    self::has_valid_cron_warm_decision($state, $now)
                    && !hash_equals((string) $state['cronToken'], $token)
                ) {
                    $rejected = 'cron_active';
                    return null;
                }

                $same_owner = 'running' === $state['cronStatus']
                    && '' !== $state['cronToken']
                    && hash_equals((string) $state['cronToken'], $token);
                if (!$same_owner) {
                    $state['cronGeneration'] = max(
                        (int) $state['decisionGeneration'],
                        (int) $state['foregroundGeneration'],
                        (int) $state['cronGeneration']
                    ) + 1;
                    $state['decisionGeneration'] = $state['cronGeneration'];
                    $state['cronStartedAt'] = $now;
                }
                $state['cronToken'] = $token;
                $state['cronStatus'] = 'running';
                $state['cronHeartbeatAt'] = $now;
                $state['cronLeaseExpiresAt'] = $now + $ttl;
                $state['cronCurrentUrl'] = '';
                $state['cronCurrentStage'] = '';
                $state['lastDecisionReason'] = $same_owner ? 'cron_renewed' : 'cron_acquired';
                $selected_generation = max(0, (int) $state['cronGeneration']);
                return $state;
            }
        );

        return !empty($mutation['success']) && '' === $rejected;
    }

    /**
     * Renew the authoritative cron lease.
     *
     * @param string $token               Cron token.
     * @param int    $ttl                 Lease duration.
     * @param int    $expected_generation Exact generation expected by the worker.
     * @param string $stage               Current stage.
     * @param string $url                 Current URL.
     * @return bool
     */
    private static function renew_cron_warm_decision($token, $ttl, $expected_generation = 0, $stage = '', $url = '')
    {
        $token = sanitize_text_field((string) $token);
        $ttl = max(10, (int) $ttl);
        $expected_generation = max(0, (int) $expected_generation);
        $stage = sanitize_key((string) $stage);
        $url = esc_url_raw((string) $url);
        $now = time();
        $activity = ('' === $stage && '' === $url)
            ? self::get_cron_warm_queue_current_activity(false)
            : array('stage' => $stage, 'url' => $url);
        $rejected = '';
        $mutation = self::mutate_warm_decision_state(
            static function ($state) use ($token, $ttl, $expected_generation, $now, $activity, &$rejected) {
                if (
                    '' === $token
                    || self::has_valid_foreground_warm_decision($state, $now)
                    || 'running' !== (string) $state['cronStatus']
                    || '' === $state['cronToken']
                    || !hash_equals((string) $state['cronToken'], $token)
                    || ($expected_generation > 0 && $expected_generation !== (int) $state['cronGeneration'])
                ) {
                    $rejected = 'ownership_mismatch';
                    return null;
                }

                $state['cronHeartbeatAt'] = $now;
                $state['cronLeaseExpiresAt'] = $now + $ttl;
                $state['cronCurrentUrl'] = esc_url_raw((string) ($activity['url'] ?? ''));
                $state['cronCurrentStage'] = sanitize_key((string) ($activity['stage'] ?? ''));
                $state['lastDecisionReason'] = 'cron_heartbeat';
                return $state;
            }
        );

        return !empty($mutation['success']) && '' === $rejected;
    }

    /**
     * Release cron ownership for the matching token.
     *
     * @param string $token  Cron token.
     * @param string $reason Release reason.
     * @return bool
     */
    private static function release_cron_warm_decision($token, $reason = 'cron_released')
    {
        $token = sanitize_text_field((string) $token);
        $reason = sanitize_key((string) $reason);
        $matched = false;
        $mutation = self::mutate_warm_decision_state(
            static function ($state) use ($token, $reason, &$matched) {
                if (
                    '' === $token
                    || '' === $state['cronToken']
                    || !hash_equals((string) $state['cronToken'], $token)
                ) {
                    return null;
                }

                $matched = true;
                $state['cronToken'] = '';
                $state['cronStatus'] = 'released';
                $state['cronHeartbeatAt'] = time();
                $state['cronLeaseExpiresAt'] = 0;
                $state['cronCurrentUrl'] = '';
                $state['cronCurrentStage'] = '';
                $state['decisionGeneration']++;
                $state['lastDecisionReason'] = '' !== $reason ? $reason : 'cron_released';
                return $state;
            }
        );

        return $matched && !empty($mutation['success']);
    }

    /**
     * Yield any cron owner to the authoritative foreground decision.
     *
     * @param string $source Foreground source.
     * @return bool
     */
    private static function yield_cron_warm_decision($source)
    {
        $source = self::normalize_foreground_warm_source($source);
        $changed = false;
        $mutation = self::mutate_warm_decision_state(
            static function ($state) use ($source, &$changed) {
                if ('running' !== (string) $state['cronStatus'] && '' === (string) $state['cronToken']) {
                    return null;
                }

                $changed = true;
                $state['cronToken'] = '';
                $state['cronStatus'] = 'released';
                $state['cronHeartbeatAt'] = time();
                $state['cronLeaseExpiresAt'] = 0;
                $state['cronCurrentUrl'] = '';
                $state['cronCurrentStage'] = '';
                $state['decisionGeneration']++;
                $state['lastDecisionReason'] = 'cron_yielded_to_' . ('' !== $source ? $source : 'foreground');
                return $state;
            }
        );

        return !empty($mutation['success']) && $changed;
    }

    /**
     * Release cron ownership without affecting foreground intent.
     *
     * @param string $reason Release reason.
     * @return bool
     */
    private static function clear_cron_warm_decision($reason = 'cron_cleared')
    {
        $reason = sanitize_key((string) $reason);
        $mutation = self::mutate_warm_decision_state(
            static function ($state) use ($reason) {
                $state['cronToken'] = '';
                $state['cronStatus'] = 'released';
                $state['cronHeartbeatAt'] = time();
                $state['cronLeaseExpiresAt'] = 0;
                $state['cronCurrentUrl'] = '';
                $state['cronCurrentStage'] = '';
                $state['decisionGeneration']++;
                $state['lastDecisionReason'] = '' !== $reason ? $reason : 'cron_cleared';
                return $state;
            }
        );

        return !empty($mutation['success']);
    }

    /**
     * Commit expired foreground/cron lease recovery.
     *
     * @return array{foregroundExpired:bool,cronExpired:bool}
     */
    private static function recover_expired_warm_decision_leases()
    {
        return self::commit_expired_warm_decision_leases();
    }
}
