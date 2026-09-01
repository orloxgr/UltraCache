<?php
/**
 * Ultra Cache Media Background Queue Trait for UltraCache media converter.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Background_Queue_Trait
{

		/** Whether the current request registered a post-response media dispatch. */
		private $background_generation_dispatch_registered = false;

		/** Context attached to the current request's deferred media dispatch. */
		private $background_generation_dispatch_context = 'queue';

		/** Per-request positive cache for an active dashboard media session. */
		private $manual_media_conversion_active_for_request = null;

		/**
		 * Retire the ambiguous legacy pause record and return the current pause record.
		 *
		 * The v1 option only stored a boolean-like administrator reason. It could not
		 * prove that a current administrator action created the pause, so it is not
		 * authoritative under the v2 contract.
		 *
		 * @return array<string,mixed>
		 */
		private function get_media_background_pause_record() {
			if (false !== get_option(self::MEDIA_BACKGROUND_PAUSED_LEGACY_OPTION, false)) {
				delete_option(self::MEDIA_BACKGROUND_PAUSED_LEGACY_OPTION);
			}

			$state = get_option(self::MEDIA_BACKGROUND_PAUSED_OPTION, array());
			if (!is_array($state)) {
				delete_option(self::MEDIA_BACKGROUND_PAUSED_OPTION);
				return array();
			}

			if ('repeated_stale_workers' === (string) ($state['reason'] ?? '')) {
				delete_option(self::MEDIA_BACKGROUND_PAUSED_OPTION);
				delete_option(self::MEDIA_STALE_WORKER_STATE_OPTION);
				return array();
			}

			$valid = !empty($state['paused'])
				&& self::MEDIA_BACKGROUND_PAUSE_SCHEMA_VERSION === absint($state['schemaVersion'] ?? 0)
				&& 'administrator_control' === sanitize_key((string) ($state['source'] ?? ''))
				&& '' !== sanitize_text_field((string) ($state['pauseId'] ?? ''))
				&& absint($state['requestedAt'] ?? 0) > 0
				&& absint($state['actorUserId'] ?? 0) > 0;

			if (!$valid) {
				if (!empty($state)) {
					delete_option(self::MEDIA_BACKGROUND_PAUSED_OPTION);
				}
				return array();
			}

			return $state;
		}


		/**
		 * Return whether an administrator has paused all media generation work.
		 *
		 * @return bool
		 */
		public function is_media_background_work_paused() {
			$state = $this->get_media_background_pause_record();
			return !empty($state['paused']);
		}

		/**
		 * Return the number of stale-worker incidents required to open the global circuit.
		 *
		 * @return int
		 */
		private function get_media_stale_worker_threshold() {
			$settings = get_option(ULTRACACHE_SETTINGS_KEY, array());
			$settings = is_array($settings) ? $settings : array();
			$configured = absint($settings['mediaStaleWorkerThreshold'] ?? 3);
			$configured = max(1, $configured);
			$filtered = apply_filters('ultracache_media_stale_worker_threshold', $configured);
			return max(1, absint($filtered));
		}

		/**
		 * Return the rolling incident window used by the global circuit breaker.
		 *
		 * @return int Seconds.
		 */
		private function get_media_stale_worker_window_seconds() {
			$window = absint(apply_filters('ultracache_media_stale_worker_window_seconds', 30 * MINUTE_IN_SECONDS));
			return max(10 * MINUTE_IN_SECONDS, min(6 * HOUR_IN_SECONDS, $window));
		}

		/**
		 * Return the quiet period after one item is quarantined.
		 *
		 * @return int Seconds.
		 */
		private function get_media_stale_worker_cooldown_seconds() {
			$cooldown = absint(apply_filters('ultracache_media_stale_worker_cooldown_seconds', 3 * MINUTE_IN_SECONDS));
			return max(MINUTE_IN_SECONDS, min(15 * MINUTE_IN_SECONDS, $cooldown));
		}

		/**
		 * Normalize one stale-worker diagnostic item.
		 *
		 * @param mixed $item Candidate diagnostic item.
		 * @param int   $now  Current Unix timestamp.
		 * @return array<string,mixed>|null
		 */
		private function normalize_media_stale_worker_item($item, $now) {
			if (!is_array($item)) {
				return null;
			}

			$at = absint($item['at'] ?? 0);
			if ($at <= 0 || $at > ($now + MINUTE_IN_SECONDS)) {
				return null;
			}

			return array(
				'at'           => $at,
				'id'           => absint($item['id'] ?? 0),
				'attachmentId' => absint($item['attachmentId'] ?? 0),
				'format'       => sanitize_key((string) ($item['format'] ?? 'best')),
				'attempts'     => absint($item['attempts'] ?? 0),
			);
		}

		/**
		 * Return normalized recent stale-worker incidents and cooldown state.
		 *
		 * Counts are stored in one-minute buckets so large thresholds do not
		 * require one full option record per worker. Only the latest 25 detailed
		 * items are retained for dashboard diagnostics.
		 *
		 * @param bool $persist_pruned Whether to persist removal of expired incidents.
		 * @return array<string,mixed>
		 */
		private function get_media_stale_worker_state($persist_pruned = false) {
			$raw = get_option(self::MEDIA_STALE_WORKER_STATE_OPTION, array());
			$raw = is_array($raw) ? $raw : array();
			$now = time();
			$window = $this->get_media_stale_worker_window_seconds();
			$cutoff = $now - $window;
			$buckets_by_minute = array();
			$recent_items = array();
			$has_native_buckets = !empty($raw['buckets']) && is_array($raw['buckets']);

			foreach ((array) ($raw['buckets'] ?? array()) as $bucket) {
				if (!is_array($bucket)) {
					continue;
				}
				$at = absint($bucket['at'] ?? 0);
				$count = absint($bucket['count'] ?? 0);
				if ($at < $cutoff || $at > ($now + MINUTE_IN_SECONDS) || $count < 1) {
					continue;
				}
				$minute = (int) (floor($at / MINUTE_IN_SECONDS) * MINUTE_IN_SECONDS);
				$buckets_by_minute[$minute] = absint($buckets_by_minute[$minute] ?? 0) + $count;
			}

			// Migrate the bounded pre-2.59.07.43 event list without losing counts.
			foreach ((array) ($raw['events'] ?? array()) as $legacy_event) {
				$item = $this->normalize_media_stale_worker_item($legacy_event, $now);
				if (null === $item || $item['at'] < $cutoff) {
					continue;
				}
				$minute = (int) (floor($item['at'] / MINUTE_IN_SECONDS) * MINUTE_IN_SECONDS);
				if (!$has_native_buckets) {
					$buckets_by_minute[$minute] = absint($buckets_by_minute[$minute] ?? 0) + 1;
				}
				$recent_items[] = $item;
			}

			foreach ((array) ($raw['recentItems'] ?? array()) as $stored_item) {
				$item = $this->normalize_media_stale_worker_item($stored_item, $now);
				if (null !== $item && $item['at'] >= $cutoff) {
					$recent_items[] = $item;
				}
			}

			ksort($buckets_by_minute, SORT_NUMERIC);
			$buckets = array();
			$incident_count = 0;
			foreach ($buckets_by_minute as $at => $count) {
				$count = absint($count);
				if ($count < 1) {
					continue;
				}
				$buckets[] = array('at' => absint($at), 'count' => $count);
				$incident_count += $count;
			}
			$bucket_limit = max(1, (int) ceil($window / MINUTE_IN_SECONDS) + 1);
			$buckets = array_slice($buckets, -$bucket_limit);
			$incident_count = array_sum(wp_list_pluck($buckets, 'count'));

			usort($recent_items, static function ($left, $right) {
				return absint($left['at'] ?? 0) <=> absint($right['at'] ?? 0);
			});
			$recent_items = array_slice($recent_items, -25);

			$cooldown_until = absint($raw['cooldownUntil'] ?? 0);
			if ($cooldown_until <= $now) {
				$cooldown_until = 0;
			}

			$normalized = array(
				'buckets'       => $buckets,
				'recentItems'   => $recent_items,
				'cooldownUntil' => $cooldown_until,
				'updatedAt'     => absint($raw['updatedAt'] ?? 0),
			);

			if ($persist_pruned && $normalized !== $raw) {
				if (empty($normalized['buckets']) && 0 === $normalized['cooldownUntil']) {
					delete_option(self::MEDIA_STALE_WORKER_STATE_OPTION);
				} else {
					update_option(self::MEDIA_STALE_WORKER_STATE_OPTION, $normalized, false);
				}
			}

			return array(
				'events'             => $normalized['recentItems'],
				'incidentCount'      => absint($incident_count),
				'threshold'          => $this->get_media_stale_worker_threshold(),
				'windowSeconds'      => $window,
				'cooldownUntil'      => $cooldown_until,
				'cooldownRemaining'  => max(0, $cooldown_until - $now),
				'buckets'            => $normalized['buckets'],
			);
		}

		/**
		 * Record one or more quarantined stale workers.
		 *
		 * @param array<int,array<string,mixed>> $items       Stale queue-row diagnostics.
		 * @param int                            $stale_count Total rows quarantined by the authoritative update.
		 * @return array<string,mixed>
		 */
		private function record_media_stale_worker_incidents($items, $stale_count) {
			$state = $this->get_media_stale_worker_state(true);
			$now = time();
			$total = absint($stale_count);
			if ($total < 1) {
				return $state;
			}
			$buckets = (array) ($state['buckets'] ?? array());
			$minute = (int) (floor($now / MINUTE_IN_SECONDS) * MINUTE_IN_SECONDS);
			$bucket_updated = false;

			foreach ($buckets as &$bucket) {
				if (absint($bucket['at'] ?? 0) === $minute) {
					$bucket['count'] = absint($bucket['count'] ?? 0) + $total;
					$bucket_updated = true;
					break;
				}
			}
			unset($bucket);
			if (!$bucket_updated && $total > 0) {
				$buckets[] = array('at' => $minute, 'count' => $total);
			}

			$recent_items = (array) ($state['events'] ?? array());
			$diagnostic_items = array_slice(array_values(array_filter((array) $items, 'is_array')), 0, min(25, $total));
			foreach ($diagnostic_items as $item) {
				$item = is_array($item) ? $item : array();
				$recent_items[] = array(
					'at'           => $now,
					'id'           => absint($item['id'] ?? 0),
					'attachmentId' => absint($item['attachmentId'] ?? 0),
					'format'       => sanitize_key((string) ($item['format'] ?? 'best')),
					'attempts'     => absint($item['attempts'] ?? 0),
				);
			}

			update_option(
				self::MEDIA_STALE_WORKER_STATE_OPTION,
				array(
					'buckets'       => array_slice(
						$buckets,
						-max(1, (int) ceil($this->get_media_stale_worker_window_seconds() / MINUTE_IN_SECONDS) + 1)
					),
					'recentItems'   => array_slice($recent_items, -25),
					'cooldownUntil' => $now + $this->get_media_stale_worker_cooldown_seconds(),
					'updatedAt'     => $now,
				),
				false
			);

			return $this->get_media_stale_worker_state(true);
		}

		/**
		 * Clear rolling stale-worker incidents after an explicit administrator resume.
		 *
		 * @return void
		 */
		private function clear_media_stale_worker_state() {
			delete_option(self::MEDIA_STALE_WORKER_STATE_OPTION);
		}

		/**
		 * Return whether new media work must wait for the stale-worker cooldown.
		 *
		 * @return bool
		 */
		private function is_media_stale_worker_cooldown_active() {
			if (false !== get_option(self::MEDIA_STALE_WORKER_STATE_OPTION, false)) {
				delete_option(self::MEDIA_STALE_WORKER_STATE_OPTION);
			}
			return false;
		}

		/**
		 * Return remaining stale-worker cooldown seconds.
		 *
		 * @return int
		 */
		private function get_media_stale_worker_cooldown_remaining() {
			return 0;
		}

		/**
		 * Apply per-item quarantine and open the global circuit only after repeated incidents.
		 *
		 * @param array<int,array<string,mixed>> $stale_items Quarantined queue rows.
		 * @param int                            $stale_count Total rows quarantined.
		 * @return array<string,mixed>
		 */
		private function apply_media_stale_worker_policy($stale_items, $stale_count) {
			$state = $this->record_media_stale_worker_incidents($stale_items, $stale_count);
			$this->background_generation_dispatch_registered = false;
			wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);

			if (function_exists('ultracache_get_lock')) {
				$dispatcher = ultracache_get_lock(self::MEDIA_BACKGROUND_DISPATCH_LOCK);
				if (!empty($dispatcher['token'])) {
					$this->release_background_generation_dispatch_lock((string) $dispatcher['token']);
				}
			}

			$open_global_circuit = (int) ($state['incidentCount'] ?? 0) >= (int) ($state['threshold'] ?? 3);
			if ($open_global_circuit) {
				update_option(
					self::MEDIA_BACKGROUND_PAUSED_OPTION,
					array(
						'paused'     => true,
						'updatedAt'  => time(),
						'reason'     => 'repeated_stale_workers',
						'message'    => __('Automatic media generation was paused after repeated stale workers. Quarantined items remain failed; inspect the server and resume explicitly.', 'ultracache'),
						'staleCount' => (int) ($state['incidentCount'] ?? 0),
						'staleItems' => array_slice((array) ($state['events'] ?? array()), -25),
					),
					false
				);
				return array_merge($state, array('globalCircuitOpen' => true));
			}

			$delay = max(15, (int) ($state['cooldownRemaining'] ?? 0));
			if ($this->get_media_queue_pending_count('best') > 0) {
				$this->schedule_background_generation_queue($delay);
			}

			return array_merge($state, array('globalCircuitOpen' => false));
		}

		/**
		 * Return the persistent media generation pause state.
		 *
		 * @return array<string,mixed>
		 */
		public function get_media_background_work_state() {
			$state = $this->get_media_background_pause_record();
			$incident_state = $this->get_media_stale_worker_state(true);
			$paused = !empty($state['paused']);
			$paused_at = absint($state['requestedAt'] ?? ($state['updatedAt'] ?? 0));
			$reason = sanitize_key((string) ($state['reason'] ?? ''));
			$message = (string) ($state['message'] ?? '');
			if ('' === $message && 'repeated_stale_workers' === $reason) {
				$message = __('Automatic media generation was paused after repeated stale workers. Quarantined items remain failed; inspect the server and resume explicitly.', 'ultracache');
			}

			$stale_count = absint($incident_state['incidentCount'] ?? 0);
			$stale_items = array_slice((array) ($incident_state['events'] ?? array()), -25);
			if ($paused && 'repeated_stale_workers' === $reason) {
				$stale_count = max($stale_count, absint($state['staleCount'] ?? 0));
				if (empty($stale_items)) {
					$stale_items = array_slice((array) ($state['staleItems'] ?? array()), -25);
				}
			}

			return array(
				'backgroundPaused'             => $paused,
				'backgroundPausedAt'           => $paused_at,
				'backgroundPauseReason'        => $reason,
				'backgroundPauseMessage'       => $message,
				'backgroundPauseContractVersion' => absint($state['schemaVersion'] ?? 0),
				'backgroundPauseSource'        => sanitize_key((string) ($state['source'] ?? '')),
				'backgroundPauseId'            => sanitize_text_field((string) ($state['pauseId'] ?? '')),
				'backgroundPauseActorUserId'   => absint($state['actorUserId'] ?? 0),
				'backgroundStaleCount'         => $stale_count,
				'backgroundStaleItems'         => $stale_items,
				'backgroundStaleThreshold'     => max(1, absint($incident_state['threshold'] ?? 3)),
				'backgroundStaleWindowSeconds' => max(0, (int) ($incident_state['windowSeconds'] ?? 0)),
				'backgroundCooldownUntil'      => max(0, (int) ($incident_state['cooldownUntil'] ?? 0)),
				'backgroundCooldownRemaining'  => max(0, (int) ($incident_state['cooldownRemaining'] ?? 0)),
			);
		}

		/**
		 * Return the lifetime of an exclusive dashboard media-conversion session.
		 *
		 * The session is renewed by every dashboard conversion request. Its expiry
		 * prevents an abandoned browser tab from disabling background work forever.
		 *
		 * @return int
		 */
		private function get_manual_media_conversion_session_ttl() {
			$ttl = (int) apply_filters('ultracache_media_manual_conversion_session_ttl', 300);
			return max(120, min(900, $ttl));
		}

		/**
		 * Normalize a dashboard media-session owner token.
		 *
		 * @param string $token Candidate token.
		 * @return string
		 */
		private function normalize_manual_media_conversion_token($token) {
			$token = trim(sanitize_text_field((string) $token));
			if ('' === $token || strlen($token) > 64 || 1 !== preg_match('/^[A-Za-z0-9-]+$/', $token)) {
				return '';
			}
			return $token;
		}

		/**
		 * Read the current exclusive dashboard media-conversion session.
		 *
		 * @return array<string,mixed>
		 */
		public function get_manual_media_conversion_state() {
			$lock = function_exists('ultracache_get_lock')
				? ultracache_get_lock(self::MEDIA_MANUAL_CONVERSION_LOCK)
				: array();
			$active = !empty($lock['token']) && empty($lock['expired']);
			return array(
				'manualSessionActive'    => $active,
				'manualSessionStartedAt' => $active ? max(0, (int) ($lock['acquiredAt'] ?? 0)) : 0,
				'manualSessionExpiresAt' => $active ? max(0, (int) ($lock['expiresAt'] ?? 0)) : 0,
			);
		}

		/**
		 * Return whether an exclusive dashboard media-conversion session is active.
		 *
		 * @return bool
		 */
		public function is_manual_media_conversion_active() {
			if (true === $this->manual_media_conversion_active_for_request) {
				return true;
			}
			$state = $this->get_manual_media_conversion_state();
			$active = !empty($state['manualSessionActive']);
			if ($active) {
				$this->manual_media_conversion_active_for_request = true;
			}
			return $active;
		}

		/**
		 * Confirm dashboard media-session ownership.
		 *
		 * @param string $token Owner token.
		 * @return bool
		 */
		public function owns_manual_media_conversion_session($token) {
			$token = $this->normalize_manual_media_conversion_token($token);
			if ('' === $token || !function_exists('ultracache_get_lock')) {
				return false;
			}
			$lock = ultracache_get_lock(self::MEDIA_MANUAL_CONVERSION_LOCK);
			return !empty($lock['token'])
				&& empty($lock['expired'])
				&& hash_equals((string) $lock['token'], $token);
		}

		/**
		 * Start or resume one exclusive dashboard media-conversion session.
		 *
		 * Existing background workers may finish the physical file already inside an
		 * encoder, but their dispatcher is invalidated and no following unit can start.
		 *
		 * @param string $preferred_token Optional token restored from a saved dashboard job.
		 * @return array<string,mixed>
		 */
		public function begin_manual_media_conversion_session($preferred_token = '') {
			if ($this->is_media_background_work_paused()) {
				$background_state = $this->get_media_background_work_state();
				return array_merge(
					array(
						'success' => false,
						'reason'  => 'background_paused',
						'message' => (string) ($background_state['backgroundPauseMessage'] ?? __('Media generation is paused by an administrator.', 'ultracache')),
					),
					$this->get_manual_media_conversion_state(),
					$background_state
				);
			}

			$this->recover_abandoned_media_queue_claims();
			if ($this->is_media_background_work_paused()) {
				$background_state = $this->get_media_background_work_state();
				return array_merge(
					array(
						'success' => false,
						'reason'  => 'background_paused',
						'message' => (string) ($background_state['backgroundPauseMessage'] ?? __('Media generation is paused by an administrator.', 'ultracache')),
					),
					$this->get_manual_media_conversion_state(),
					$background_state
				);
			}
			if ($this->is_media_stale_worker_cooldown_active()) {
				return array_merge(
					array(
						'success' => false,
						'reason'  => 'stale_worker_cooldown',
						'message' => __('Media conversion is cooling down after a stale worker was quarantined. It will resume automatically when the quiet period ends.', 'ultracache'),
					),
					$this->get_manual_media_conversion_state(),
					$this->get_media_background_work_state()
				);
			}
			if ($this->get_media_queue_processing_count() > 0) {
				return array_merge(
					array(
						'success' => false,
						'reason'  => 'background_worker_active',
						'message' => __('A background media worker is still processing an item. Manual conversion can start after that claim completes or its expired lease is recovered automatically.', 'ultracache'),
					),
					$this->get_manual_media_conversion_state()
				);
			}

			if (!function_exists('ultracache_acquire_lock') || !function_exists('ultracache_get_lock')) {
				return array(
					'success' => false,
					'reason'  => 'lock_unavailable',
					'message' => __('The dashboard media-conversion lock is unavailable.', 'ultracache'),
				);
			}

			$preferred_token = $this->normalize_manual_media_conversion_token($preferred_token);
			$current = ultracache_get_lock(self::MEDIA_MANUAL_CONVERSION_LOCK);
			if (!empty($current['token']) && empty($current['expired'])) {
				if ('' !== $preferred_token && hash_equals((string) $current['token'], $preferred_token)) {
					return $this->renew_manual_media_conversion_session($preferred_token);
				}
				return array_merge(
					array(
						'success' => false,
						'reason'  => 'manual_session_active',
						'message' => __('Another dashboard media conversion is already running.', 'ultracache'),
					),
					$this->get_manual_media_conversion_state()
				);
			}

			$token = '' !== $preferred_token ? $preferred_token : wp_generate_uuid4();
			$ttl = $this->get_manual_media_conversion_session_ttl();
			$payload = array(
				'context'     => 'dashboard',
				'acquired_at' => time(),
				'user_id'     => get_current_user_id(),
				'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '',
			);
			if (!ultracache_acquire_lock(self::MEDIA_MANUAL_CONVERSION_LOCK, $token, $ttl, $payload)) {
				return array_merge(
					array(
						'success' => false,
						'reason'  => 'manual_session_active',
						'message' => __('Another dashboard media conversion is already running.', 'ultracache'),
					),
					$this->get_manual_media_conversion_state()
				);
			}

			$this->manual_media_conversion_active_for_request = true;
			$this->background_generation_dispatch_registered = false;
			wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);
			$dispatcher = ultracache_get_lock(self::MEDIA_BACKGROUND_DISPATCH_LOCK);
			if (!empty($dispatcher['token'])) {
				$this->release_background_generation_dispatch_lock((string) $dispatcher['token']);
			}
			// Recovery watchdog for an abandoned browser tab. Normal completion releases
			// the session and starts the background dispatcher immediately.
			wp_schedule_single_event(time() + $ttl + 15, self::BACKGROUND_QUEUE_HOOK);

			return array_merge(
				array(
					'success' => true,
					'token'   => $token,
				),
				$this->get_manual_media_conversion_state()
			);
		}

		/**
		 * Renew an owner-held dashboard media-conversion session.
		 *
		 * @param string $token Owner token.
		 * @return array<string,mixed>
		 */
		public function renew_manual_media_conversion_session($token) {
			$token = $this->normalize_manual_media_conversion_token($token);
			if ('' === $token || !$this->owns_manual_media_conversion_session($token) || !function_exists('ultracache_renew_lock')) {
				return array_merge(
					array(
						'success' => false,
						'reason'  => 'manual_session_lost',
						'message' => __('The dashboard media-conversion session expired or changed owner.', 'ultracache'),
					),
					$this->get_manual_media_conversion_state()
				);
			}

			$ttl = $this->get_manual_media_conversion_session_ttl();
			$renewed = ultracache_renew_lock(
				self::MEDIA_MANUAL_CONVERSION_LOCK,
				$token,
				$ttl,
				array(
					'context'    => 'dashboard',
					'renewed_at' => time(),
					'user_id'    => get_current_user_id(),
				)
			);
			if ($renewed) {
				$this->manual_media_conversion_active_for_request = true;
				wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);
				wp_schedule_single_event(time() + $ttl + 15, self::BACKGROUND_QUEUE_HOOK);
			}
			return array_merge(
				array(
					'success' => $renewed,
					'token'   => $renewed ? $token : '',
					'reason'  => $renewed ? '' : 'manual_session_lost',
					'message' => $renewed ? '' : __('The dashboard media-conversion session could not be renewed.', 'ultracache'),
				),
				$this->get_manual_media_conversion_state()
			);
		}

		/**
		 * End an owner-held dashboard media-conversion session and resume background work.
		 *
		 * @param string $token Owner token.
		 * @return array<string,mixed>
		 */
		public function end_manual_media_conversion_session($token, $resume_background = true) {
			$token = $this->normalize_manual_media_conversion_token($token);
			$state = $this->get_manual_media_conversion_state();
			if (empty($state['manualSessionActive'])) {
				return array_merge(
					array(
						'success'  => true,
						'released' => false,
						'reason'   => '',
					),
					$state
				);
			}

			if ('' === $token || !$this->owns_manual_media_conversion_session($token)) {
				return array_merge(
					array(
						'success'  => false,
						'released' => false,
						'reason'   => 'manual_session_lost',
					),
					$state
				);
			}

			$released = function_exists('ultracache_release_lock')
				&& ultracache_release_lock(self::MEDIA_MANUAL_CONVERSION_LOCK, $token);
			if ($released) {
				$this->manual_media_conversion_active_for_request = null;
				wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);
			}
			$resumed_background_work = false;
			if ($released && $resume_background && !$this->is_media_background_work_paused()) {
				if (method_exists($this, 'reset_stale_media_queue_items')) {
					$this->recover_abandoned_media_queue_claims();
				}
				$queue_status = $this->get_media_queue_status('best');
				$queue_available = !array_key_exists('enabled', $queue_status) || !empty($queue_status['enabled']);
				$resumable_work = $queue_available && (!empty($queue_status['attachmentPending'])
					|| !empty($queue_status['localAssetPending'])
					|| !empty($queue_status['unitPending'])
					|| !empty($queue_status['unitProcessing'])
					|| !empty($queue_status['unitUnmaterializedParents'])
					|| empty($queue_status['buildComplete'])
					|| empty($queue_status['unitInventoryComplete']));
				if ($this->is_background_media_queue_enabled() && $resumable_work) {
					$this->queue_background_generation_dispatch('manual_dashboard_complete');
					$this->schedule_background_generation_queue(15);
					$resumed_background_work = true;
				}
			}

			return array_merge(
				array(
					'success'               => $released,
					'released'              => $released,
					'resumedBackgroundWork' => $resumed_background_work,
					'reason'                => $released ? '' : 'manual_session_lost',
				),
				$this->get_manual_media_conversion_state()
			);
		}

		/**
		 * Pause or resume every UltraCache media generation entry point.
		 *
		 * The physical image currently inside an encoder cannot be interrupted, but
		 * no subsequent unit can start after the pause becomes visible.
		 *
		 * @param bool $paused Requested pause state.
		 * @return array<string,mixed>
		 */
		public function set_media_background_work_paused($paused, $context = array()) {
			$paused = (bool) $paused;
			$context = is_array($context) ? $context : array();
			$reset_processing = 0;

			if ($paused) {
				$pause_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('uc-media-pause-', true);
				update_option(
					self::MEDIA_BACKGROUND_PAUSED_OPTION,
					array(
						'schemaVersion' => self::MEDIA_BACKGROUND_PAUSE_SCHEMA_VERSION,
						'paused'        => true,
						'pauseId'       => sanitize_text_field((string) $pause_id),
						'requestedAt'   => time(),
						'updatedAt'     => time(),
						'reason'        => 'administrator',
						'source'        => 'administrator_control',
						'actorUserId'   => absint($context['actorUserId'] ?? get_current_user_id()),
						'requestUri'    => sanitize_text_field((string) ($context['requestUri'] ?? '')),
						'message'       => __('Media generation is paused by an administrator.', 'ultracache'),
					),
					false
				);
				delete_option(self::MEDIA_BACKGROUND_PAUSED_LEGACY_OPTION);
				$this->background_generation_dispatch_registered = false;
				wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);

				if (function_exists('ultracache_get_lock')) {
					$lock = ultracache_get_lock(self::MEDIA_BACKGROUND_DISPATCH_LOCK);
					if (!empty($lock['token'])) {
						$this->release_background_generation_dispatch_lock((string) $lock['token']);
					}
				}

				if (method_exists($this, 'reset_active_media_queue_items')) {
					$reset_processing = (int) $this->reset_active_media_queue_items();
				}
				$this->invalidate_media_work_summary_cache();
			} else {
				delete_option(self::MEDIA_BACKGROUND_PAUSED_OPTION);
				delete_option(self::MEDIA_BACKGROUND_PAUSED_LEGACY_OPTION);
				$this->clear_media_stale_worker_state();
				if (method_exists($this, 'reset_stale_media_queue_items')) {
					$this->recover_abandoned_media_queue_claims();
				}
				if ($this->is_background_media_queue_enabled() && $this->get_media_queue_pending_count('best') > 0) {
					$this->queue_background_generation_dispatch('manual_resume');
				}
			}

			return array_merge(
				array(
					'success'         => true,
					'resetProcessing' => $reset_processing,
					'pending'         => $this->get_media_queue_pending_count('best'),
				),
				$this->get_media_background_work_state()
			);
		}

		public function maybe_generate_avif_on_upload($metadata, $attachment_id) {
			if ($this->is_media_background_work_paused() || !$this->is_media_optimization_enabled() || !$this->is_generate_on_upload_enabled()) {
				return $metadata;
			}

			if (!$this->is_supported()) {
				return $metadata;
			}

			$attachment_id = absint($attachment_id);
			if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
				return $metadata;
			}

			$this->enqueue_attachment_for_background_generation($attachment_id);

			return $metadata;
		}

		private function enqueue_attachment_for_background_generation($attachment_id) {
			$attachment_id = absint($attachment_id);
			if ($attachment_id <= 0) {
				return;
			}

			if (!$this->upsert_media_queue_item($attachment_id, 'best', 'pending', '', 0)) {
				return;
			}
			if (!$this->last_media_queue_upsert_created_work()) {
				return;
			}
			$this->invalidate_media_work_summary_cache();
			$this->queue_background_generation_dispatch('upload');
		}

		private function dequeue_attachment_from_background_generation($attachment_id) {
			$attachment_id = absint($attachment_id);
			if ($attachment_id <= 0) {
				return;
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			if ($this->media_queue_table_exists()) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache removes rows from its own custom media queue table; queue state must be database-truth and not object-cached.
				$wpdb->delete($table, array('attachment_id' => $attachment_id), array('%d'));
			}
		}

		/**
		 * Defer worker dispatch until after WordPress has completed the response and
		 * page-cache store. PHP shutdown functions registered by the plugin run after
		 * WordPress's shutdown action, preventing the worker from purging a page before
		 * the current request finishes writing that page to cache.
		 *
		 * @param string $context Dispatch source.
		 * @return void
		 */
		private function queue_background_generation_dispatch($context = 'queue') {
			if ($this->background_generation_dispatch_registered) {
				return;
			}
			if ($this->is_media_background_work_paused() || $this->is_manual_media_conversion_active()) {
				return;
			}

			$this->background_generation_dispatch_context = sanitize_key((string) $context);

			$this->background_generation_dispatch_registered = true;
			register_shutdown_function(array($this, 'dispatch_queued_background_generation'));
		}

		/**
		 * Launch the single background dispatcher after the current response ends.
		 *
		 * @return void
		 */
		public function dispatch_queued_background_generation() {
			if ($this->is_media_background_work_paused() || $this->is_manual_media_conversion_active()) {
				$this->background_generation_dispatch_registered = false;
				return;
			}

			if (!$this->background_generation_dispatch_registered) {
				return;
			}

			$this->background_generation_dispatch_registered = false;
			$context = '' !== $this->background_generation_dispatch_context
				? $this->background_generation_dispatch_context
				: 'queue';
			$this->dispatch_background_generation_queue($context);
		}

		/**
		 * Return the dispatcher lock lifetime.
		 *
		 * @param string $context Dispatcher context.
		 * @return int
		 */
		private function get_background_generation_dispatch_lock_ttl($context = 'dispatch') {
			$ttl = (int) apply_filters('ultracache_media_background_dispatch_lock_ttl', self::MEDIA_QUEUE_PROCESSING_TTL, sanitize_key((string) $context));
			return max(30, min(300, $ttl));
		}

		/**
		 * Acquire the single dispatcher lock used by immediate and cron workers.
		 *
		 * @param string $context Dispatcher context.
		 * @return string Owner token, or an empty string when another dispatcher owns the lock.
		 */
		private function acquire_background_generation_dispatch_lock($context = 'dispatch') {
			if (!function_exists('ultracache_acquire_lock')) {
				return '';
			}

			$token = wp_generate_uuid4();
			$ttl   = $this->get_background_generation_dispatch_lock_ttl($context);
			$payload = array(
				'context'     => sanitize_key((string) $context),
				'acquired_at' => time(),
				'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '',
			);

			return ultracache_acquire_lock(self::MEDIA_BACKGROUND_DISPATCH_LOCK, $token, $ttl, $payload) ? $token : '';
		}

		/**
		 * Confirm that a token owns the dispatcher lock.
		 *
		 * @param string $token Owner token.
		 * @return bool
		 */
		private function owns_background_generation_dispatch_lock($token) {
			if ('' === (string) $token || !function_exists('ultracache_get_lock')) {
				return false;
			}

			$lock = ultracache_get_lock(self::MEDIA_BACKGROUND_DISPATCH_LOCK);
			return !empty($lock['token'])
				&& empty($lock['expired'])
				&& hash_equals((string) $lock['token'], (string) $token);
		}

		/**
		 * Renew the dispatcher lock while a worker chain is active.
		 *
		 * @param string $token   Owner token.
		 * @param int    $ttl     Lock lifetime.
		 * @param string $context Worker context.
		 * @return bool
		 */
		private function renew_background_generation_dispatch_lock($token, $ttl, $context = 'worker') {
			if ('' === (string) $token || !function_exists('ultracache_renew_lock')) {
				return false;
			}

			$ttl = max(30, min(300, (int) $ttl));
			return ultracache_renew_lock(
				self::MEDIA_BACKGROUND_DISPATCH_LOCK,
				$token,
				$ttl,
				array(
					'context'    => sanitize_key((string) $context),
					'renewed_at' => time(),
				)
			);
		}

		/**
		 * Release the dispatcher lock only for its owner.
		 *
		 * @param string $token Owner token.
		 * @return void
		 */
		private function release_background_generation_dispatch_lock($token) {
			if ('' !== (string) $token && function_exists('ultracache_release_lock')) {
				ultracache_release_lock(self::MEDIA_BACKGROUND_DISPATCH_LOCK, $token);
			}
		}

		/**
		 * Create the HMAC used by the unauthenticated same-site worker endpoint.
		 *
		 * @param string $token   Dispatcher token.
		 * @param int    $expires Signature expiry timestamp.
		 * @return string
		 */
		private function get_background_generation_worker_signature($token, $expires) {
			$message = (string) $token . '|' . (int) $expires;
			return function_exists('ultracache_internal_sign')
				? ultracache_internal_sign('media-background-worker', $message)
				: hash_hmac('sha256', 'ultracache|media-background-worker|' . $message, wp_salt('auth'));
		}

		/**
		 * Validate a signed background worker request.
		 *
		 * @param string $token     Dispatcher token.
		 * @param int    $expires   Signature expiry timestamp.
		 * @param string $signature HMAC signature.
		 * @return bool
		 */
		public function verify_background_generation_worker_request($token, $expires, $signature) {
			if ($this->is_media_background_work_paused() || $this->is_manual_media_conversion_active() || $this->is_media_stale_worker_cooldown_active()) {
				return false;
			}

			$token     = sanitize_text_field((string) $token);
			$expires   = (int) $expires;
			$signature = strtolower(trim((string) $signature));
			$now       = time();

			if (
				'' === $token
				|| strlen($token) > 64
				|| 1 !== preg_match('/^[A-Za-z0-9-]+$/', $token)
				|| $expires < ($now - 5)
				|| $expires > ($now + 300)
				|| 64 !== strlen($signature)
				|| 1 !== preg_match('/^[a-f0-9]{64}$/', $signature)
			) {
				return false;
			}

			$expected = $this->get_background_generation_worker_signature($token, $expires);
			return hash_equals($expected, $signature) && $this->owns_background_generation_dispatch_lock($token);
		}

		/**
		 * Send one signed, non-blocking loopback request to the media worker.
		 *
		 * @param string $token Dispatcher token.
		 * @return bool
		 */
		private function send_background_generation_worker_request($token) {
			if ($this->is_media_background_work_paused() || $this->is_manual_media_conversion_active() || $this->is_media_stale_worker_cooldown_active() || !$this->owns_background_generation_dispatch_lock($token)) {
				return false;
			}

			$expires   = time() + 120;
			$signature = $this->get_background_generation_worker_signature($token, $expires);
			$url       = rest_url('ultracache/v1/media/background-worker');
			$origin    = function_exists('ultracache_get_configured_site_origin') ? (string) ultracache_get_configured_site_origin() : '';
			$scheme    = strtolower((string) wp_parse_url($origin, PHP_URL_SCHEME));
			if (in_array($scheme, array('http', 'https'), true)) {
				$url = set_url_scheme((string) $url, $scheme);
			}
			$args      = array(
				'timeout'             => 0.01,
				'blocking'            => false,
				'redirects'           => 0,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
				'sslverify'           => (bool) apply_filters('https_local_ssl_verify', false, $url),
				'headers'             => array(
					'Cache-Control' => 'no-cache',
				),
				'body'                => array(
					'token'     => $token,
					'expires'   => $expires,
					'signature' => $signature,
				),
			);

			$response = wp_remote_post($url, $args);
			return !is_wp_error($response);
		}

		/**
		 * Schedule the cron watchdog used only when the immediate worker stalls.
		 *
		 * @param int $delay Delay in seconds.
		 * @return void
		 */
		private function schedule_background_generation_queue($delay = 120) {
			if ($this->is_media_background_work_paused()) {
				wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);
				return;
			}
			if ($this->is_manual_media_conversion_active()) {
				return;
			}

			$delay = max(15, min(300, (int) $delay));
			$cooldown_remaining = $this->get_media_stale_worker_cooldown_remaining();
			if ($cooldown_remaining > 0) {
				$delay = max($delay, min(300, $cooldown_remaining));
			}
			$scheduled_for  = wp_next_scheduled(self::BACKGROUND_QUEUE_HOOK);
			$desired_time   = time() + $delay;

			if (!$scheduled_for) {
				wp_schedule_single_event($desired_time, self::BACKGROUND_QUEUE_HOOK);
				return;
			}

			// Replace a later watchdog when an immediate dispatch failure requires
			// an earlier fallback. The dispatcher lock ensures only one request can
			// perform this reschedule for a worker chain.
			if ((int) $scheduled_for > ($desired_time + 5)) {
				wp_unschedule_event((int) $scheduled_for, self::BACKGROUND_QUEUE_HOOK);
				wp_schedule_single_event($desired_time, self::BACKGROUND_QUEUE_HOOK);
			}
		}

		/**
		 * Acquire the dispatcher lock and launch one immediate worker chain.
		 *
		 * @param string $context Dispatch source.
		 * @return bool True only for the request that acquired and dispatched the worker.
		 */
		private function dispatch_background_generation_queue($context = 'queue') {
			if ($this->is_manual_media_conversion_active() || !$this->is_background_media_queue_enabled() || !$this->is_supported()) {
				return false;
			}

			$this->recover_abandoned_media_queue_claims();
			if ($this->is_media_background_work_paused()) {
				return false;
			}
			if ($this->is_media_stale_worker_cooldown_active()) {
				$this->schedule_background_generation_queue($this->get_media_stale_worker_cooldown_remaining());
				return false;
			}
			if ($this->get_media_queue_processing_count() > 0) {
				$this->schedule_background_generation_queue(60);
				return false;
			}

			$token = $this->acquire_background_generation_dispatch_lock($context);
			if ('' === $token) {
				return false;
			}

			$ttl = $this->get_background_generation_dispatch_lock_ttl($context);
			$this->schedule_background_generation_queue($ttl + 30);

			if ($this->send_background_generation_worker_request($token)) {
				return true;
			}

			// Keep a short lock after a loopback failure so a burst of visitors does
			// not create a burst of failing loopback requests. Cron takes over after
			// the shortened lock expires.
			$this->renew_background_generation_dispatch_lock($token, 30, 'dispatch_failed');
			$this->schedule_background_generation_queue(45);
			return false;
		}

		public function maybe_schedule_pending_background_generation() {
			if ($this->is_manual_media_conversion_active() || !$this->is_background_media_queue_enabled()) {
				return;
			}

			// Frontend init must not poll the custom queue table. Upload and on-demand
			// discovery dispatch directly; admin/AJAX/CLI contexts can recover a lost
			// dispatcher. Cron is excluded here because the dedicated cron hook must
			// process one unit directly when loopback dispatch is unavailable.
			$is_maintenance_context = (defined('WP_CLI') && WP_CLI)
				|| (function_exists('wp_doing_ajax') && wp_doing_ajax())
				|| (function_exists('is_admin') && is_admin());

			if (!$is_maintenance_context) {
				return;
			}

			$maintenance_key = 'ultracache_media_queue_init_maintenance_v1';
			$maintenance_token = 'media-queue-maintenance-' . wp_generate_uuid4();
			if (!function_exists('ultracache_acquire_lock') || !ultracache_acquire_lock(
				$maintenance_key,
				$maintenance_token,
				10 * MINUTE_IN_SECONDS,
				array('type' => 'media_queue_init_maintenance', 'startedAt' => time())
			)) {
				return;
			}

			$this->run_media_queue_units_migration_maintenance(25, 2.0);
			$this->recover_abandoned_media_queue_claims();
			if ($this->is_media_background_work_paused()) {
				return;
			}
			if ($this->is_media_stale_worker_cooldown_active()) {
				$this->schedule_background_generation_queue($this->get_media_stale_worker_cooldown_remaining());
				return;
			}
			if ($this->get_media_queue_processing_count() > 0) {
				$this->schedule_background_generation_queue(60);
				return;
			}
			$queue_status = $this->get_media_queue_status('best');
			if ($this->get_media_queue_pending_count('best') <= 0 && !empty($queue_status['unitInventoryComplete'])) {
				return;
			}

			$this->dispatch_background_generation_queue('maintenance');
		}

		/**
		 * Run one bounded worker step and schedule a later continuation.
		 *
		 * @param string $dispatch_token Dispatcher lock owner token.
		 * @param string $context        Worker source.
		 * @return array<string,mixed>
		 */
		private function run_background_generation_worker($dispatch_token, $context = 'worker') {
			if (!$this->owns_background_generation_dispatch_lock($dispatch_token)) {
				return array(
					'success' => false,
					'reason'  => 'stale_dispatch',
				);
			}

			if ($this->is_manual_media_conversion_active() || !$this->is_background_media_queue_enabled() || !$this->is_supported()) {
				$this->release_background_generation_dispatch_lock($dispatch_token);
				return array(
					'success' => false,
					'reason'  => 'disabled',
				);
			}

			$this->recover_abandoned_media_queue_claims();
			if ($this->is_media_background_work_paused()) {
				$this->release_background_generation_dispatch_lock($dispatch_token);
				wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);
				return array_merge(
					array(
						'success' => false,
						'paused'  => true,
						'reason'  => 'repeated_stale_workers',
					),
					$this->get_media_background_work_state()
				);
			}
			if ($this->is_media_stale_worker_cooldown_active()) {
				$this->release_background_generation_dispatch_lock($dispatch_token);
				$this->schedule_background_generation_queue($this->get_media_stale_worker_cooldown_remaining());
				return array_merge(
					array(
						'success' => false,
						'paused'  => true,
						'reason'  => 'stale_worker_cooldown',
					),
					$this->get_media_background_work_state()
				);
			}
			if ($this->get_media_queue_processing_count() > 0) {
				$this->renew_background_generation_dispatch_lock($dispatch_token, 60, 'processing_active');
				$this->schedule_background_generation_queue(75);
				return array(
					'success' => false,
					'paused'  => true,
					'reason'  => 'processing_active',
				);
			}
			$pre_status = $this->get_media_queue_status('best');
			if ($this->get_media_queue_pending_count('best') <= 0 && !empty($pre_status['unitInventoryComplete'])) {
				$this->release_background_generation_dispatch_lock($dispatch_token);
				wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);
				$failed = max(0, (int) ($pre_status['failed'] ?? 0));
				$unit_failed = max(0, (int) ($pre_status['unitFailed'] ?? 0));
				$complete = $failed <= 0 && $unit_failed <= 0 && !empty($pre_status['isComplete']);
				return array_merge(
					$pre_status,
					array(
						'success'   => $complete,
						'complete'  => $complete,
						'remaining' => 0,
						'reason'    => $complete ? '' : 'failed',
					)
				);
			}

			$this->renew_background_generation_dispatch_lock(
				$dispatch_token,
				$this->get_background_generation_dispatch_lock_ttl($context),
				$context
			);

			$result = $this->process_media_queue_batch(array(
				'limit'        => 1,
				'format'       => 'best',
				'only_missing' => true,
				'time_budget'  => 8,
			));

			if ($this->is_media_background_work_paused() || $this->is_manual_media_conversion_active()) {
				$manual_session_active = $this->is_manual_media_conversion_active();
				$this->release_background_generation_dispatch_lock($dispatch_token);
				if (!$manual_session_active) {
					wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);
				}
				return array_merge(
					$result,
					array(
						'success' => false,
						'paused'  => true,
						'reason'  => $manual_session_active ? 'manual_session_active' : 'background_paused',
					)
				);
			}

			$remaining = max(0, (int) ($result['remaining'] ?? 0));
			if (!empty($result['isComplete']) && !empty($result['unitIsComplete'])) {
				$this->release_background_generation_dispatch_lock($dispatch_token);
				wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);
				return $result;
			}
			if ($remaining <= 0 && !empty($result['unitInventoryComplete'])) {
				$this->release_background_generation_dispatch_lock($dispatch_token);
				wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);
				return $result;
			}
			if ($remaining <= 0 && empty($result['unitInventoryComplete'])) {
				$this->release_background_generation_dispatch_lock($dispatch_token);
				$this->schedule_background_generation_queue(30);
				$result['reason'] = 'unit_inventory_materializing';
				return $result;
			}

			$reason = (string) ($result['reason'] ?? $result['pauseReason'] ?? '');
			if ('locked' === $reason || 'already_claimed' === $reason) {
				$this->renew_background_generation_dispatch_lock($dispatch_token, 60, 'conversion_locked');
				$this->schedule_background_generation_queue(75);
				return $result;
			}

			if (empty($result['success']) || !empty($result['failedThisRun'])) {
				$this->renew_background_generation_dispatch_lock($dispatch_token, 180, 'conversion_backoff');
				$this->schedule_background_generation_queue(190);
				return $result;
			}

			// End this HTTP/cron worker after one bounded unit. A delayed cron event
			// starts the next unit only after this request has returned, preventing a
			// self-perpetuating loopback chain from occupying the PHP worker pool.
			$this->release_background_generation_dispatch_lock($dispatch_token);
			$this->schedule_background_generation_queue(30);

			return $result;
		}

		/**
		 * Execute one signed HTTP worker step.
		 *
		 * @param string $dispatch_token Dispatcher owner token.
		 * @return array<string,mixed>
		 */
		public function handle_background_generation_worker($dispatch_token) {
			return $this->run_background_generation_worker(sanitize_text_field((string) $dispatch_token), 'http_worker');
		}

		/**
		 * WP-Cron watchdog and fallback worker.
		 *
		 * @return void
		 */
		public function process_background_generation_queue() {
			if ($this->is_manual_media_conversion_active() || !$this->is_background_media_queue_enabled() || !$this->is_supported()) {
				return;
			}

			$this->recover_abandoned_media_queue_claims();
			if ($this->is_media_background_work_paused()) {
				wp_clear_scheduled_hook(self::BACKGROUND_QUEUE_HOOK);
				return;
			}
			if ($this->is_media_stale_worker_cooldown_active()) {
				$this->schedule_background_generation_queue($this->get_media_stale_worker_cooldown_remaining());
				return;
			}
			if ($this->get_media_queue_processing_count() > 0) {
				$this->schedule_background_generation_queue(60);
				return;
			}
			$queue_status = $this->get_media_queue_status('best');
			if ($this->get_media_queue_pending_count('best') <= 0 && !empty($queue_status['unitInventoryComplete'])) {
				return;
			}

			$token = $this->acquire_background_generation_dispatch_lock('cron_fallback');
			if ('' === $token) {
				// An immediate worker chain still owns the dispatcher. Keep one future
				// watchdog so recovery remains available if that chain later stalls.
				$this->schedule_background_generation_queue(120);
				return;
			}

			$this->run_background_generation_worker($token, 'cron_fallback');
		}
}
