<?php
/**
 * Ultra Cache Media Background Queue Trait for UltraCache media converter.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Background_Queue_Trait
{

		public function maybe_generate_avif_on_upload($metadata, $attachment_id) {
			if (!$this->is_media_optimization_enabled() || !$this->is_generate_on_upload_enabled()) {
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

			$this->upsert_media_queue_item($attachment_id, 'best', 'pending', '', 0);
			$this->invalidate_media_work_summary_cache();
			$this->schedule_background_generation_queue();
		}

		private function dequeue_attachment_from_background_generation($attachment_id) {
			$attachment_id = absint($attachment_id);
			if ($attachment_id <= 0) {
				return;
			}

			$queue = $this->get_background_generation_queue();
			if (isset($queue[(string) $attachment_id])) {
				unset($queue[(string) $attachment_id]);
				$this->persist_background_generation_queue($queue);
			}

			global $wpdb;
			$table = $this->get_media_queue_table_name();
			if ($this->media_queue_table_exists()) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache removes rows from its own custom media queue table; queue state must be database-truth and not object-cached.
				$wpdb->delete($table, array('attachment_id' => $attachment_id), array('%d'));
			}
		}

		private function get_background_generation_queue() {
			$queue = get_option(self::BACKGROUND_QUEUE_OPTION, array());
			if (!is_array($queue)) {
				return array();
			}

			$normalized = array();
			foreach ($queue as $attachment_id => $queued_at) {
				$attachment_id = absint($attachment_id);
				if ($attachment_id <= 0) {
					continue;
				}
				$normalized[(string) $attachment_id] = max(0, (int) $queued_at);
			}

			return $normalized;
		}

		private function persist_background_generation_queue(array $queue) {
			if (empty($queue)) {
				delete_option(self::BACKGROUND_QUEUE_OPTION);
				return;
			}

			update_option(self::BACKGROUND_QUEUE_OPTION, $queue, false);
		}

		private function schedule_background_generation_queue() {
			if (!wp_next_scheduled(self::BACKGROUND_QUEUE_HOOK)) {
				wp_schedule_single_event(time() + 15, self::BACKGROUND_QUEUE_HOOK);
			}
		}

		public function maybe_schedule_pending_background_generation() {
			if ($this->get_media_queue_pending_count() <= 0 && empty($this->get_background_generation_queue())) {
				return;
			}

			$this->schedule_background_generation_queue();
		}

		public function process_background_generation_queue() {
			if (!$this->is_media_optimization_enabled() || !$this->is_generate_on_upload_enabled() || !$this->is_supported()) {
				return;
			}

			if (get_transient(self::BACKGROUND_QUEUE_LOCK)) {
				return;
			}

			set_transient(self::BACKGROUND_QUEUE_LOCK, 1, 5 * MINUTE_IN_SECONDS);

			try {
				$legacy_queue = $this->get_background_generation_queue();
				foreach (array_keys($legacy_queue) as $attachment_id) {
					$attachment_id = absint($attachment_id);
					if ($attachment_id > 0) {
						$this->upsert_media_queue_item($attachment_id, 'best', 'pending', '', 0);
					}
				}
				$this->persist_background_generation_queue(array());

				$batch_size = (int) apply_filters('ucwp_media_queue_batch_size', 2);
				$batch_size = max(1, min(10, $batch_size));

				$result = $this->process_media_queue_batch(array(
					'limit' => $batch_size,
					'format' => 'best',
					'only_missing' => true,
				));

				if (!empty($result['remaining'])) {
					$this->schedule_background_generation_queue();
				}
			} finally {
				delete_transient(self::BACKGROUND_QUEUE_LOCK);
			}
		}
}
