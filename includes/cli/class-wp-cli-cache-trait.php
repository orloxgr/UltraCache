<?php
/**
 * WP-CLI command group for UltraCache.
 */

defined('ABSPATH') || exit;

if (!trait_exists('UCWP_CLI_Cache_Trait')) {
    trait UCWP_CLI_Cache_Trait
    {
        public function purge($args, $assoc_args)
        {
            $engine = $this->get_engine();
            if (!$engine) {
                WP_CLI::error('Cache engine not available.');
            }

            $target_url = !empty($assoc_args['cache-url']) ? $assoc_args['cache-url'] : '';
            if (!empty($target_url) && !empty($assoc_args['all'])) {
                WP_CLI::error('Use either --all or --cache-url, not both.');
            }

            if (!empty($target_url)) {
                if (!method_exists($engine, 'purge_url')) {
                    WP_CLI::error('Single-URL purge is not available.');
                }

                $url = $this->require_local_site_url($target_url, $engine, 'Please provide a valid local site URL for --cache-url.');
                $purged = (bool) $engine->purge_url($url);
                if (!$purged) {
                    WP_CLI::warning('No cache files matched that URL.');
                    return;
                }

                WP_CLI::success('Purged cache for ' . $url);
                return;
            }

            if (!method_exists($engine, 'purge_all')) {
                WP_CLI::error('Full purge is not available.');
            }

            $purged = (bool) $engine->purge_all();
            if (!$purged) {
                WP_CLI::error('Full cache purge is already running or the purge lock could not be acquired.');
            }

            WP_CLI::success('Purged the full cache.');
        }

        private function get_warm_buckets_from_assoc_args($assoc_args)
        {
            if (empty($assoc_args['buckets'])) {
                return null;
            }

            $buckets = array_values(array_unique(array_intersect(
                array('orig', 'webp', 'avif'),
                array_map('trim', explode(',', (string) $assoc_args['buckets']))
            )));
            if (empty($buckets)) {
                WP_CLI::error('Invalid bucket list. Use orig,webp,avif.');
            }

            return $buckets;
        }

        private function warm_url_list($engine, array $urls, $buckets = null, $purge_first = false, $build_css_bundle = false)
        {
            $urls = array_values(array_filter($urls));
            if (empty($urls)) {
                WP_CLI::warning('No URLs to warm.');
                return;
            }

            $progress = \WP_CLI\Utils\make_progress_bar('Warming cache', count($urls));
            $warmed = 0;
            $failed = 0;

            foreach ($urls as $url) {
                if ($purge_first && method_exists($engine, 'purge_url')) {
                    $engine->purge_url($url);
                }

                $warm_args = array('build_css_bundle' => (bool) $build_css_bundle);
                if (is_array($buckets)) {
                    $warm_args['buckets'] = $buckets;
                }
                $result = $engine->warm_url($url, $warm_args);
                if (!empty($result['success'])) {
                    $warmed++;
                } else {
                    $failed++;
                    WP_CLI::warning($url . ' -> ' . (!empty($result['message']) ? $result['message'] : 'Warm failed.'));
                }
                $progress->tick();
            }

            $progress->finish();
            WP_CLI::success(sprintf('Warm finished. Success: %d, failed: %d.', $warmed, $failed));
        }

        /**
         * Warm cache files.
         *
         * ## OPTIONS
         *
         * [--cache-url=<url>]
         * : Warm a single local URL.
         *
         * Note: `--url` is reserved by WP-CLI as a global parameter. Use `--cache-url` here.
         *
         * [--limit=<number>]
         * : Limit how many crawl URLs will be warmed.
         *
         * [--buckets=<list>]
         * : Comma-separated buckets: orig,webp,avif.
         *
         * [--purge-first]
         * : Purge each URL before warming it.
         */

        public function warm($args, $assoc_args)
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_url')) {
                WP_CLI::error('Cache warming is not available.');
            }

            $buckets = $this->get_warm_buckets_from_assoc_args($assoc_args);

            $target_url = !empty($assoc_args['cache-url']) ? $assoc_args['cache-url'] : '';
            if (!empty($target_url) && !empty($assoc_args['all'])) {
                WP_CLI::error('Use either --all or --cache-url, not both.');
            }

            if (!empty($target_url)) {
                $urls = array($this->require_local_site_url($target_url, $engine, 'Please provide a valid local site URL for --cache-url.'));
            } else {
                if (!method_exists($engine, 'get_crawl_urls')) {
                    WP_CLI::error('URL discovery is not available.');
                }
                $urls = (array) $engine->get_crawl_urls();
            }

            $limit = isset($assoc_args['limit']) ? max(0, absint($assoc_args['limit'])) : 0;
            if ($limit > 0) {
                $urls = array_slice($urls, 0, $limit);
            }

            $this->warm_url_list($engine, $urls, $buckets, isset($assoc_args['purge-first']));
        }

        /**
         * Warm up HTML cache for all crawlable public URLs.
         *
         * ## OPTIONS
         *
         * [--limit=<number>]
         * : Limit how many crawl URLs will be warmed.
         *
         * [--buckets=<list>]
         * : Comma-separated buckets: orig,webp,avif.
         *
         * [--purge-first]
         * : Purge each URL before warming it.
         */

        public function warm_html_all($args, $assoc_args)
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'get_crawl_urls') || !method_exists($engine, 'warm_url')) {
                WP_CLI::error('Cache warming is not available.');
            }

            $urls = (array) $engine->get_crawl_urls();
            $limit = isset($assoc_args['limit']) ? max(0, absint($assoc_args['limit'])) : 0;
            if ($limit > 0) {
                $urls = array_slice($urls, 0, $limit);
            }

            $this->warm_url_list($engine, $urls, $this->get_warm_buckets_from_assoc_args($assoc_args), isset($assoc_args['purge-first']));
        }

        /**
         * Warm up HTML cache for the front page only.
         *
         * ## OPTIONS
         *
         * [--buckets=<list>]
         * : Comma-separated buckets: orig,webp,avif.
         *
         * [--purge-first]
         * : Purge the front page before warming it.
         */

        public function warm_frontpage_html($args, $assoc_args)
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_frontpage_html')) {
                WP_CLI::error('Front page HTML warming is not available.');
            }

            $frontpage_url = home_url('/');
            if (isset($assoc_args['purge-first']) && method_exists($engine, 'purge_url')) {
                $engine->purge_url($frontpage_url);
            }

            $warm_args = array();
            $buckets = $this->get_warm_buckets_from_assoc_args($assoc_args);
            if (is_array($buckets)) {
                $warm_args['buckets'] = $buckets;
            }

            $result = $engine->warm_frontpage_html($warm_args);
            if (!empty($result['success'])) {
                WP_CLI::success(!empty($result['message']) ? $result['message'] : 'Front page HTML cache warmed.');
                return;
            }

            WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Front page HTML warm failed.');
        }

        /**
         * Warm up front page HTML cache and rebuild the front page CSS bundle.
         *
         * ## OPTIONS
         *
         * [--purge-first]
         * : Purge the front page before warming it.
         */

        public function warm_frontpage_html_css($args, $assoc_args)
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_frontpage_html_with_css')) {
                WP_CLI::error('Front page HTML + CSS warming is not available.');
            }

            $frontpage_url = home_url('/');
            if (isset($assoc_args['purge-first']) && method_exists($engine, 'purge_url')) {
                $engine->purge_url($frontpage_url);
            }

            $result = $engine->warm_frontpage_html_with_css();
            if (!empty($result['success']) || !empty($result['skipped'])) {
                WP_CLI::success(!empty($result['message']) ? $result['message'] : 'Front page HTML + CSS warm completed.');
                return;
            }

            WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Front page HTML + CSS warm failed.');
        }

        /**
         * Warm up HTML cache for all crawlable public URLs, then rebuild the front page CSS bundle.
         *
         * ## OPTIONS
         *
         * [--limit=<number>]
         * : Limit how many crawl URLs will be warmed.
         *
         * [--buckets=<list>]
         * : Comma-separated buckets: orig,webp,avif.
         *
         * [--purge-first]
         * : Purge each URL before warming it.
         */

        public function warm_html_all_css($args, $assoc_args)
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'get_crawl_urls') || !method_exists($engine, 'warm_url') || !method_exists($engine, 'build_frontpage_css_bundle')) {
                WP_CLI::error('Site-wide HTML + CSS warming is not available.');
            }

            $urls = (array) $engine->get_crawl_urls();
            $limit = isset($assoc_args['limit']) ? max(0, absint($assoc_args['limit'])) : 0;
            if ($limit > 0) {
                $urls = array_slice($urls, 0, $limit);
            }

            $this->warm_url_list($engine, $urls, $this->get_warm_buckets_from_assoc_args($assoc_args), isset($assoc_args['purge-first']), true);

            $result = $engine->build_frontpage_css_bundle();
            if (!empty($result['success']) || !empty($result['skipped'])) {
                WP_CLI::success(!empty($result['message']) ? $result['message'] : 'All URLs warmed and front page CSS bundle rebuilt.');
                return;
            }

            WP_CLI::error(!empty($result['message']) ? $result['message'] : 'All URLs were warmed, but front page CSS rebuild failed.');
        }

        /**
         * Show UltraCache status, diagnostics, storage, settings, or analytics data.
         *
         * ## OPTIONS
         *
         * [--section=<section>]
         * : Section to show. One of summary, settings, diagnostics, storage, stats, analytics, all. Default: summary.
         *
         * [--format=<format>]
         * : Output format. One of table, json, yaml. Default: table.
         *
         * ## EXAMPLES
         *
         *     wp ultracache status
         *     wp ultracache status --section=storage --format=json
         *     wp ultracache status --section=settings
         */

        public function status($args, $assoc_args)
        {
            $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
            $section = !empty($assoc_args['section']) ? strtolower((string) $assoc_args['section']) : 'summary';
            if (!in_array($section, array('summary', 'settings', 'diagnostics', 'storage', 'stats', 'analytics', 'all'), true)) {
                WP_CLI::error('Invalid section. Use summary, settings, diagnostics, storage, stats, analytics, or all.');
            }

            $settings = $this->get_dashboard_settings();
            $stats = $this->get_dashboard_stats();
            $diagnostics = $this->get_dashboard_diagnostics();
            unset($stats['diagnostics']);

            $payload = array();
            switch ($section) {
                case 'settings':
                    $payload = $this->redact_dashboard_settings_for_output($settings);
                    break;
                case 'diagnostics':
                    $payload = $diagnostics;
                    break;
                case 'storage':
                    $payload = !empty($diagnostics['cacheStorage']) && is_array($diagnostics['cacheStorage']) ? $diagnostics['cacheStorage'] : array();
                    break;
                case 'stats':
                case 'analytics':
                    $payload = $stats;
                    break;
                case 'all':
                    $payload = array(
                        'settings' => $this->redact_dashboard_settings_for_output($settings),
                        'diagnostics' => $diagnostics,
                        'stats' => $stats,
                    );
                    break;
                case 'summary':
                default:
                    $last = !empty($diagnostics['lastEvent']) && is_array($diagnostics['lastEvent']) ? $diagnostics['lastEvent'] : array();
                    $payload = array(
                        'pageCacheEnabled' => !empty($settings['pageCacheEnabled']),
                        'pageCacheActive' => !empty($diagnostics['pageCache']['active']),
                        'objectCacheEnabled' => !empty($settings['objectCacheEnabled']),
                        'objectCacheActive' => !empty($diagnostics['objectCache']['active']),
                        'objectCacheAvailable' => !empty($diagnostics['objectCache']['available']),
                        'gzipEnabled' => !empty($settings['gzipEnabled']),
                        'brotliEnabled' => !empty($settings['brotliEnabled']),
                        'mediaOptimizationEnabled' => !empty($settings['mediaOptimizationEnabled']),
                        'cacheSizeHuman' => (string) ($stats['cacheSizeHuman'] ?? ''),
                        'pagesCached' => (int) ($stats['pagesCached'] ?? ($stats['pageCacheFiles'] ?? 0)),
                        'pageCacheHits' => (int) ($stats['pageCacheHits'] ?? 0),
                        'pageCacheMisses' => (int) ($stats['pageCacheMisses'] ?? 0),
                        'pageCacheBypasses' => (int) ($stats['pageCacheBypasses'] ?? 0),
                        'pageCacheHitRatio' => (float) ($stats['pageCacheHitRatio'] ?? 0),
                        'pageCacheStaleHits' => (int) ($stats['pageCacheStaleHits'] ?? 0),
                        'pageCacheBackgroundRevalidations' => (int) ($stats['pageCacheBackgroundRevalidations'] ?? 0),
                        'objectCacheEntries' => (int) ($stats['objectCacheEntries'] ?? 0),
                        'objectCacheHits' => (int) ($stats['objectCacheHits'] ?? 0),
                        'objectCacheMisses' => (int) ($stats['objectCacheMisses'] ?? 0),
                        'objectCacheHitRatio' => (float) ($stats['objectCacheHitRatio'] ?? 0),
                        'optimizedImages' => (int) ($stats['imagesOptimized'] ?? ($stats['optimizedImages'] ?? 0)),
                        'avifImages' => (int) ($stats['avifImagesOptimized'] ?? ($stats['avifFiles'] ?? 0)),
                        'webpImages' => (int) ($stats['webpImagesOptimized'] ?? ($stats['webpFiles'] ?? 0)),
                        'lastEventStatus' => (string) ($last['status'] ?? ''),
                        'lastEventReason' => (string) ($last['reason'] ?? ''),
                        'lastEventBucket' => (string) ($last['bucket'] ?? ''),
                        'lastEventTime' => (string) ($last['time_mysql'] ?? ($last['time'] ?? '')),
                        'lastPurgeTime' => (string) (($stats['lastPurge']['time_mysql'] ?? ($stats['lastPurge']['time'] ?? ''))),
                        'lastWarmTime' => (string) (($stats['lastWarm']['time_mysql'] ?? ($stats['lastWarm']['time'] ?? ''))),
                    );
                    break;
            }

            $this->output_assoc($payload, $format);
        }

        /**
         * Inspect cacheability for a URL.
         *
         * ## OPTIONS
         *
         * <url>
         * : URL to inspect.
         *
         * [--format=<format>]
         * : Output format: table or json. Default: table.
         */

        public function inspect($args, $assoc_args)
        {
            if (empty($args[0])) {
                WP_CLI::error('Please provide a URL to inspect.');
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'inspect_url')) {
                WP_CLI::error('URL inspection is not available.');
            }

            $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
            $result = $engine->inspect_url((string) $args[0]);
            if (!is_array($result)) {
                WP_CLI::error('Unexpected inspect response.');
            }

            $this->output_assoc($result, $format);
        }

        /**
         * Run the scheduled UltraCache cleanup routine once.
         *
         * ## EXAMPLES
         *
         *     wp ultracache cleanup
         */

        public function cleanup($args, $assoc_args)
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'run_scheduled_cache_cleanup')) {
                WP_CLI::error('Scheduled cleanup is not available.');
            }

            $result = Ultra_Cache_WP::run_scheduled_cache_cleanup();
            if (empty($result['success'])) {
                WP_CLI::error(!empty($result['message']) ? $result['message'] : 'Cleanup failed.');
            }

            WP_CLI::success(sprintf(
                'Scheduled cleanup finished. CSS bundles deleted: %d. Recognized CSS bundle files before/after: %d/%d. Old orphan-like eligible before: %d. Recent orphan-like protected by grace before: %d. Protected by cached HTML before: %d. Cached HTML CSS refs before: %d. Cleanup limit: %d/run. Grace: %d seconds. Runtime artifacts deleted: %d. Warmed %d URL(s).',
                (int) ($result['cssBundleFilesDeleted'] ?? 0),
                (int) ($result['cssBundleFilesBefore'] ?? 0),
                (int) ($result['cssBundleFilesAfter'] ?? 0),
                (int) ($result['cssBundleOldOrphanLikeBefore'] ?? 0),
                (int) ($result['cssBundleRecentOrphanLikeBefore'] ?? 0),
                (int) ($result['cssBundleProtectedByCachedHtmlBefore'] ?? 0),
                (int) ($result['cssBundleCachedHtmlRefsBefore'] ?? 0),
                (int) ($result['cssBundleCleanupLimit'] ?? 0),
                (int) ($result['cssBundleGraceSeconds'] ?? 0),
                (int) ($result['runtimeArtifactsDeleted'] ?? 0),
                (int) ($result['warmed'] ?? 0)
            ));
        }

        /**
         * Clean safe old runtime lock/test artifacts.
         *
         * ## OPTIONS
         *
         * [--dry-run]
         * : Preview matching artifacts without deleting them.
         *
         * [--max-age-minutes=<number>]
         * : Minimum age for regular runtime lock markers. Default: 10. Test dummy markers are eligible immediately if not actively locked.
         *
         * [--format=<format>]
         * : Output format: table, json, or yaml. Default: table.
         *
         * ## EXAMPLES
         *
         *     wp ultracache cleanup_artifacts --dry-run
         *     wp ultracache cleanup_artifacts --max-age-minutes=10
         */

        public function cleanup_artifacts($args, $assoc_args)
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'cleanup_runtime_artifacts')) {
                WP_CLI::error('Runtime artifact cleanup is not available.');
            }

            $format = !empty($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
            $max_age_minutes = isset($assoc_args['max-age-minutes']) ? max(1, absint($assoc_args['max-age-minutes'])) : 10;
            $result = Ultra_Cache_WP::cleanup_runtime_artifacts(array(
                'dry_run' => !empty($assoc_args['dry-run']),
                'max_age_seconds' => $max_age_minutes * MINUTE_IN_SECONDS,
            ));

            if (!empty($result['items']) && 'table' === $format) {
                $items = array();
                foreach ((array) $result['items'] as $item) {
                    $items[] = array(
                        'file' => (string) ($item['file'] ?? ''),
                        'action' => (string) ($item['action'] ?? ''),
                        'reason' => (string) ($item['reason'] ?? ''),
                        'ageSeconds' => (int) ($item['ageSeconds'] ?? 0),
                    );
                }
                if (!empty($items)) {
                    WP_CLI::line('Runtime artifact cleanup candidates:');
                    \WP_CLI\Utils\format_items('table', $items, array('file', 'action', 'reason', 'ageSeconds'));
                }
            }

            $summary = array(
                'success' => !empty($result['success']) ? 'yes' : 'no',
                'dryRun' => !empty($result['dryRun']) ? 'yes' : 'no',
                'maxAgeSeconds' => (int) ($result['maxAgeSeconds'] ?? 0),
                'scanned' => (int) ($result['scanned'] ?? 0),
                'matched' => (int) ($result['matched'] ?? 0),
                'deleted' => (int) ($result['deleted'] ?? 0),
                'wouldDelete' => (int) ($result['wouldDelete'] ?? 0),
                'skippedActive' => (int) ($result['skippedActive'] ?? 0),
                'skippedYoung' => (int) ($result['skippedYoung'] ?? 0),
                'skippedUnknown' => (int) ($result['skippedUnknown'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'message' => (string) ($result['message'] ?? ''),
            );

            if ('table' === $format) {
                $this->output_assoc($summary, 'table');
                if (empty($result['success'])) {
                    WP_CLI::error('Runtime artifact cleanup completed with failures.');
                }
                return;
            }

            $this->output_assoc($result, $format);
            if (empty($result['success'])) {
                WP_CLI::halt(1);
            }
        }

    }
}
