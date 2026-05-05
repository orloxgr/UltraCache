<?php
/**
 * WP-CLI integration for UltraCache.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/cli/class-wp-cli-helpers-trait.php';
require_once __DIR__ . '/cli/class-wp-cli-cache-trait.php';
require_once __DIR__ . '/cli/class-wp-cli-media-trait.php';
require_once __DIR__ . '/cli/class-wp-cli-settings-stats-trait.php';
require_once __DIR__ . '/cli/class-wp-cli-integrations-trait.php';

if (!class_exists('UCWP_CLI_Command') && defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command')) {
    class UCWP_CLI_Command extends WP_CLI_Command
    {
        use UCWP_CLI_Helpers_Trait;
        use UCWP_CLI_Cache_Trait;
        use UCWP_CLI_Media_Trait;
        use UCWP_CLI_Settings_Stats_Trait;
        use UCWP_CLI_Integrations_Trait;

        /**
         * UltraCache command router.
         *
         * Running `wp ultracache` without arguments prints the full command reference.
         * Additional words are routed manually so user-friendly commands such as
         * `wp ultracache media rebuild` work while the root command can still print
         * a rich custom help screen.
         *
         * ## OPTIONS
         *
         * [<command>...]
         * : Optional UltraCache command path, for example `media rebuild`, `status`,
         * `purge`, or `warm_frontpage_html_css`. Omit to show the full command reference.
         *
         * [--format=<format>]
         * : Output format for commands that support it.
         *
         * [--section=<section>]
         * : Status section for `status`.
         *
         * [--url=<url>]
         * : URL for diagnostics commands that accept --url.
         *
         * [--cache-url=<url>]
         * : URL for cache purge/warm/Varnish commands.
         *
         * [--limit=<number>]
         * : Limit for commands that support a numeric limit.
         *
         * [--buckets=<list>]
         * : Cache buckets, for example orig,webp,avif.
         *
         * [--purge-first]
         * : Purge before warming where supported.
         *
         * [--media-format=<format>]
         * : Media output target. One of both, best, avif, or webp.
         *
         * [--only-missing]
         * : Media repair mode; generate only missing optimized variants.
         *
         * [--batch-size=<number>]
         * : Internal media queue chunk size.
         *
         * [--time-budget=<seconds>]
         * : Internal media queue per-chunk time budget.
         *
         * [--max-batches=<number>]
         * : Stop media processing after N internal chunks.
         *
         * [--ids=<ids>]
         * : Comma-separated media attachment IDs.
         *
         * [--queue-limit=<number>]
         * : Limit queued media rows during queue rebuild.
         *
         * [--pages-per-minute=<number>]
         * : Cron warm pages per minute.
         *
         * [--last]
         * : Read last diagnostics output where supported.
         *
         * [--clear]
         * : Clear data where supported.
         *
         * [--all]
         * : Apply action to all relevant targets where supported.
         */
        public function __invoke($args, $assoc_args)
        {
            $args = is_array($args) ? array_values($args) : array();

            if (empty($args)) {
                $this->output_command_reference($args, $assoc_args);
                return;
            }

            $command = strtolower((string) array_shift($args));

            if ('help' === $command || 'commands' === $command) {
                $this->output_command_reference($args, $assoc_args);
                return;
            }

            $map = array(
                'purge' => 'purge',
                'warm' => 'warm',
                'warm_html_all' => 'warm_html_all',
                'warm_frontpage_html' => 'warm_frontpage_html',
                'warm_frontpage_html_css' => 'warm_frontpage_html_css',
                'warm_html_all_css' => 'warm_html_all_css',
                'status' => 'status',
                'inspect' => 'inspect',
                'cleanup' => 'cleanup',
                'cleanup_artifacts' => 'cleanup_artifacts',
                'media' => 'media',
                'settings' => 'settings',
                'stats' => 'stats',
                'self_test' => 'self_test',
                'flush_object_cache' => 'flush_object_cache',
                'varnish' => 'varnish',
                'cron_warm' => 'cron_warm',
                'google_fonts_rebuild' => 'google_fonts_rebuild',
                'store_profile' => 'store_profile',
                'css_diagnostics' => 'css_diagnostics',
            );

            if (empty($map[$command]) || !method_exists($this, $map[$command])) {
                WP_CLI::error(sprintf('Unknown UltraCache command `%s`. Run `wp ultracache` to see all commands.', $command));
            }

            $method = $map[$command];
            $this->{$method}($args, $assoc_args);
        }

        /**
         * Show the complete UltraCache WP-CLI command reference.
         *
         * Running `wp ultracache` without a subcommand should be useful for site owners,
         * not only for developers who already know the hidden command/action names.
         *
         * ## OPTIONS
         *
         * [--format=<format>]
         * : Output format. One of text or json. Default: text.
         */
        public function output_command_reference($args, $assoc_args)
        {
            $format = !empty($assoc_args['format']) ? strtolower((string) $assoc_args['format']) : 'text';
            $commands = $this->get_ultracache_cli_command_reference();

            if ('json' === $format) {
                WP_CLI::line(function_exists('wp_json_encode') ? wp_json_encode($commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : json_encode($commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return;
            }

            if ('text' !== $format && '' !== $format) {
                WP_CLI::error('Invalid --format. Use text or json.');
            }

            $this->print_ultracache_cli_command_reference($commands);
        }

        /**
         * Show the complete UltraCache WP-CLI command reference.
         *
         * ## OPTIONS
         *
         * [--format=<format>]
         * : Output format. One of text or json. Default: text.
         */
        public function help($args, $assoc_args)
        {
            $this->output_command_reference($args, $assoc_args);
        }

        /**
         * Show the complete UltraCache WP-CLI command reference.
         *
         * ## OPTIONS
         *
         * [--format=<format>]
         * : Output format. One of text or json. Default: text.
         */
        public function commands($args, $assoc_args)
        {
            $this->output_command_reference($args, $assoc_args);
        }

        private function print_ultracache_cli_command_reference(array $groups)
        {
            WP_CLI::line('UltraCache WP-CLI command reference');
            WP_CLI::line('Version: ' . (defined('UCWP_VERSION') ? UCWP_VERSION : 'unknown'));
            WP_CLI::line('');
            WP_CLI::line('Usage:');
            WP_CLI::line('  wp ultracache');
            WP_CLI::line('  wp ultracache help');
            WP_CLI::line('  wp ultracache commands --format=json');
            WP_CLI::line('');
            WP_CLI::line('Tip: WordPress global flags still work, for example --url=<site>, --user=<id>, --path=<path>.');
            WP_CLI::line('');

            foreach ($groups as $group) {
                WP_CLI::line(strtoupper((string) ($group['title'] ?? 'Commands')));
                WP_CLI::line(str_repeat('-', strlen((string) ($group['title'] ?? 'Commands'))));

                foreach ((array) ($group['commands'] ?? array()) as $command) {
                    WP_CLI::line('  ' . (string) ($command['command'] ?? ''));
                    if (!empty($command['description'])) {
                        WP_CLI::line('      ' . (string) $command['description']);
                    }
                    if (!empty($command['examples']) && is_array($command['examples'])) {
                        foreach ($command['examples'] as $example) {
                            WP_CLI::line('      e.g. ' . (string) $example);
                        }
                    }
                }

                WP_CLI::line('');
            }
        }

        private function get_ultracache_cli_command_reference()
        {
            return array(
                array(
                    'title' => 'Discovery / Help',
                    'commands' => array(
                        array(
                            'command' => 'wp ultracache',
                            'description' => 'Show this complete command reference.',
                        ),
                        array(
                            'command' => 'wp ultracache help [--format=text|json]',
                            'description' => 'Show the same complete reference explicitly.',
                        ),
                        array(
                            'command' => 'wp ultracache commands [--format=text|json]',
                            'description' => 'Alias for help; useful for scripts when --format=json is needed.',
                        ),
                    ),
                ),
                array(
                    'title' => 'Status / Diagnostics',
                    'commands' => array(
                        array(
                            'command' => 'wp ultracache status [--section=summary|settings|diagnostics|storage|stats|analytics|all] [--format=table|json|yaml]',
                            'description' => 'Show UltraCache status, settings, diagnostics, storage, or analytics.',
                            'examples' => array('wp ultracache status --section=all --format=json'),
                        ),
                        array(
                            'command' => 'wp ultracache inspect <url> [--format=table|json|yaml]',
                            'description' => 'Inspect cacheability for a local URL.',
                            'examples' => array('wp ultracache inspect https://example.com/ --format=json'),
                        ),
                        array(
                            'command' => 'wp ultracache self_test [--format=table|json]',
                            'description' => 'Run UltraCache self-test checks.',
                        ),
                        array(
                            'command' => 'wp ultracache css_diagnostics [<url>] [--url=<url>] [--last] [--format=table|json|yaml]',
                            'description' => 'Run or read CSS/STORE critical-path diagnostics.',
                        ),
                        array(
                            'command' => 'wp ultracache store_profile [show|clear] [--format=table|json|yaml]',
                            'description' => 'Show or clear the last STORE profiler report.',
                        ),
                    ),
                ),
                array(
                    'title' => 'Page Cache',
                    'commands' => array(
                        array(
                            'command' => 'wp ultracache purge',
                            'description' => 'Purge the full UltraCache page cache.',
                        ),
                        array(
                            'command' => 'wp ultracache purge --cache-url=<url>',
                            'description' => 'Purge one local URL only.',
                        ),
                        array(
                            'command' => 'wp ultracache warm [--cache-url=<url>] [--limit=<number>] [--buckets=orig,webp,avif] [--purge-first]',
                            'description' => 'Warm one URL or discovered crawl URLs.',
                        ),
                        array(
                            'command' => 'wp ultracache warm_frontpage_html [--buckets=orig,webp,avif] [--purge-first]',
                            'description' => 'Warm front page HTML cache only.',
                        ),
                        array(
                            'command' => 'wp ultracache warm_frontpage_html_css [--purge-first]',
                            'description' => 'Warm front page HTML and rebuild the homepage/frontpage CSS bundle.',
                        ),
                        array(
                            'command' => 'wp ultracache warm_html_all [--limit=<number>] [--buckets=orig,webp,avif] [--purge-first]',
                            'description' => 'Warm HTML cache for all crawlable public URLs.',
                        ),
                        array(
                            'command' => 'wp ultracache warm_html_all_css [--limit=<number>] [--buckets=orig,webp,avif] [--purge-first]',
                            'description' => 'Warm all crawlable URLs and rebuild CSS bundle output.',
                        ),
                        array(
                            'command' => 'wp ultracache cleanup',
                            'description' => 'Run scheduled cleanup once.',
                        ),
                        array(
                            'command' => 'wp ultracache cleanup_artifacts [--dry-run] [--max-age-minutes=<number>] [--format=table|json|yaml]',
                            'description' => 'Clean safe old runtime lock/test artifacts from UltraCache locks storage.',
                        ),
                    ),
                ),
                array(
                    'title' => 'Media Optimization',
                    'commands' => array(
                        array(
                            'command' => 'wp ultracache media rebuild',
                            'description' => 'Rebuild and regenerate all optimized AVIF/WebP images until complete. Default output target: both.',
                        ),
                        array(
                            'command' => 'wp ultracache media rebuild --only-missing',
                            'description' => 'Repair only missing optimized AVIF/WebP images without replacing existing variants. Default output target: both.',
                        ),
                        array(
                            'command' => 'wp ultracache media process',
                            'description' => 'Process the current media queue until complete. If the queue is empty, it is rebuilt first.',
                        ),
                        array(
                            'command' => 'wp ultracache media status [--media-format=both|best|avif|webp] [--format=table|json|yaml]',
                            'description' => 'Show AVIF/WebP media queue and persistent uploads/uc-images storage status.',
                        ),
                        array(
                            'command' => 'wp ultracache media retry-failed',
                            'description' => 'Reset failed media queue rows back to pending.',
                        ),
                        array(
                            'command' => 'wp ultracache media clear-completed',
                            'description' => 'Clear completed rows from the media queue.',
                        ),
                        array(
                            'command' => 'wp ultracache media process --ids=12,34,56',
                            'description' => 'Generate optimized variants for specific attachment IDs.',
                        ),
                        array(
                            'command' => 'wp ultracache media process-batch [--batch-size=25] [--time-budget=20]',
                            'description' => 'Advanced diagnostic action: process one internal chunk only.',
                        ),
                    ),
                ),
                array(
                    'title' => 'Settings / Analytics',
                    'commands' => array(
                        array(
                            'command' => 'wp ultracache settings list [--format=table|json|yaml]',
                            'description' => 'List current dashboard settings with sensitive values redacted.',
                        ),
                        array(
                            'command' => 'wp ultracache settings get <key> [--format=table|json|yaml]',
                            'description' => 'Read one setting key.',
                        ),
                        array(
                            'command' => 'wp ultracache settings set <key> <value> [--format=table|json|yaml]',
                            'description' => 'Update one setting key through UltraCache settings persistence.',
                        ),
                        array(
                            'command' => 'wp ultracache stats [show|reset] [--format=table|json]',
                            'description' => 'Show or reset cache/object-cache analytics counters.',
                        ),
                    ),
                ),
                array(
                    'title' => 'Object Cache',
                    'commands' => array(
                        array(
                            'command' => 'wp ultracache flush_object_cache',
                            'description' => 'Flush UltraCache object cache through the public WP-CLI command name.',
                        ),
                    ),
                ),
                array(
                    'title' => 'Cron Warm Queue',
                    'commands' => array(
                        array(
                            'command' => 'wp ultracache cron_warm status',
                            'description' => 'Show cron warm queue state.',
                        ),
                        array(
                            'command' => 'wp ultracache cron_warm start',
                            'description' => 'Start or reset the cron warm queue.',
                        ),
                        array(
                            'command' => 'wp ultracache cron_warm tick [--pages-per-minute=<number>]',
                            'description' => 'Process one cron warm tick. Useful for real server cron.',
                            'examples' => array('wp ultracache cron_warm tick --pages-per-minute=60'),
                        ),
                        array(
                            'command' => 'wp ultracache cron_warm stop',
                            'description' => 'Stop the cron warm queue.',
                        ),
                    ),
                ),
                array(
                    'title' => 'Integrations',
                    'commands' => array(
                        array(
                            'command' => 'wp ultracache varnish test',
                            'description' => 'Test Varnish integration.',
                        ),
                        array(
                            'command' => 'wp ultracache varnish flush-all',
                            'description' => 'Flush/BAN the current host through Varnish integration.',
                        ),
                        array(
                            'command' => 'wp ultracache varnish flush-url --cache-url=<url>',
                            'description' => 'Flush/BAN one URL through Varnish integration.',
                        ),
                        array(
                            'command' => 'wp ultracache google_fonts_rebuild [--clear]',
                            'description' => 'Rebuild the local Google Fonts cache from scan URLs.',
                        ),
                    ),
                ),
            );
        }
    }
}


if (!class_exists('Ultra_Cache_WP_CLI')) {
    final class Ultra_Cache_WP_CLI
    {
        public static function register()
        {
            if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
                return;
            }

            if (!class_exists('UCWP_CLI_Command')) {
                return;
            }

            if (defined('UCWP_WP_CLI_REGISTERED')) {
                return;
            }

            define('UCWP_WP_CLI_REGISTERED', true);

            // 2.56.191: register UltraCache once as a manual router. WP-CLI does
            // not allow adding subcommands under an invokable root command, and an
            // invokable class without a variadic synopsis swallows `media rebuild`
            // as invalid positional arguments. The router accepts the command path
            // itself and dispatches to the existing command handlers.
            WP_CLI::add_command('ultracache', 'UCWP_CLI_Command');
        }
    }
}
