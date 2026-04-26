=== UltraCache ===
Contributors: orloxgr
Tags: cache, performance, redis, varnish, webp
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.55.73
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Static HTML page caching, cache warming, Redis object cache, Varnish-aware purging, AVIF/WebP optimization, and operator-friendly diagnostics.

== Description ==

UltraCache is a production-oriented performance plugin for WordPress sites that want practical caching controls, fast warm-up tools, Redis-backed object caching, optional Varnish integration, media conversion, and a diagnostics-heavy admin dashboard.

Google Fonts localization is optional and, when enabled, downloads Google Fonts CSS/font assets into the local UltraCache cache so the frontend can serve local copies instead of the original Google-hosted URLs.

Main capabilities include:

* Full-page HTML caching through a managed advanced-cache drop-in
* Stale-while-revalidate cache delivery
* Homepage, menu, and full-site warm-up tools
* Menu-only warm-up actions for fast wins
* Optional Redis object cache drop-in management
* Optional Varnish-aware purge workflows
* Optional Google Fonts localization with outbound fetches only when explicitly enabled
* AVIF generation with WebP fallback
* Homepage/shared/per-page CSS bundle generation
* Gzip and Brotli sidecars when supported
* WooCommerce-safe bypass logic
* WP-CLI support for operators

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate **UltraCache** through the Plugins screen in WordPress.
3. Open the **UltraCache** dashboard in wp-admin.
4. Review Diagnostics before enabling caching features.
5. Enable page cache first, then test purge and warm-up flows.
6. Enable Redis, media conversion, and advanced optimizations only after validation.

== Frequently Asked Questions ==

= What should I press first for fast results? =

Use **Warm Up Menu HTML Cache + CSS Bundles** when you want a fast, low-risk warm-up focused on the URLs users are most likely to visit first.

= When should I use Full Site warm-up? =

Use **Warm Up Full Site HTML Cache** or **Warm Up Full Site HTML Cache + CSS Bundles** after larger content updates or when you want to rebuild the full public cache scope.

= Does it support Redis and Varnish? =

Yes. UltraCache can manage Redis-backed object caching and includes Varnish-aware purge workflows when your server stack supports them.

== Changelog ==

= 2.55.73 =
* Disabled Warm Cache actions until Page Caching is enabled.
* Disabled CSS-bundle warm actions until CSS Bundling is enabled.
* Added matching REST guards so direct warm/CSS bundle calls cannot run when their required feature is off.

= 2.55.46 =
* Added SR7/Revolution lifecycle-aware LCP priority handling without hardcoded generated IDs.
* Kept SR7 priority behavior controlled by visible LCP Image Priority and Fix sliders / hero sections settings.
* Renamed manual preload fields for clearer UI wording.
* Added ID-independent SR7 first-slide LCP priority handling for dynamic Revolution Slider image layers.
* Avoids manual preloading of generated SR7 /revslider/o/ image-list placeholders when Slider Safe Mode or LCP Image Priority is active, preventing Chrome preloaded-but-unused warnings.
* Keeps manual priority preloads available for normal image/style/script/font URLs.



= 2.55.44 =
* Added Fix sliders / hero sections advanced toggle for Revolution Slider/SR7 and common hero slider pages.
* Slider-safe responses skip the risky frontend mutations that can hide or delay hero sliders.
= 2.55.44 =
* Made CSS Bundle output fail-safe after the 2.55.38 frontend blank-page regression: Stage 1 now injects the bundle non-destructively, while Stage 2 keeps async fallback links for original stylesheets.
* Hardened the page CSS bundle replacement path so it verifies the generated bundle file before touching the document head and reconstructs the head without preg_replace replacement-string side effects.
* Moved Cache Engine Advanced settings and exclusions into its own one-column accordion card below the main Cache Engine/Compression settings grid.

= 2.55.33 =
* Hardened the delayed JS loader so it preserves script attributes through a compact encoded attribute map.
* Added safer delayed execution with sequential loading, an 8-second per-script fallback timeout, and compatibility fallbacks for older placeholders.
* Added dependency safeguards so local scripts with enqueued dependents are not delayed.
* Expanded built-in delay exclusions for builders, menus, sliders, video players, popups, forms, and fragile frontend runtimes.

= 2.55.32 =
* Moved runtime self-hosted font CSS rewriting behind a separate advanced toggle and removed native DOM prototype monkey-patching from the default path.
* Made Asset Chain Cleanup more conservative with built-in/request/HTML exclusions and a dashboard-managed exclusion list.
* Narrowed product-filter asset cleanup to plugin-specific handles/paths instead of broad fragments such as tooltipster, icheck, html_types/slider, or by_sku.
* Cleaned up legacy/mapping issues for scheduled cleanup warming and media-converter settings fallbacks.

= 2.55.31 =
* Added frontend on-demand image conversion safeguards with per-image/per-format lock files, per-request limits, and a short time budget before starting new conversions.
* Kept Generate on Demand as the explicit dashboard control, while restricting runtime generation to safe frontend GET/HEAD requests.

= 2.55.30 =
* Analytics hot path cleanup: page-cache HIT counters are buffered and flushed in batches instead of updating analytics.json on every cached response.
* APCu in-memory counters are used when available, with compact file buffering as fallback so dashboard/CLI stats remain compatible.

= 2.55.29 =
* Added native `rename()` fallback to `ucwp_safe_rename()` so atomic replacements do not depend only on `WP_Filesystem`.
* Added native recursive directory deletion fallback to `ucwp_safe_rmdir()` for cache cleanup paths.
* Tightened page-cache atomic write verification so failed replacements do not silently look successful.
* Hardened full-cache purge recursion with safe directory scans and symlink-aware traversal.

= 2.55.28 =
* Fixed Redis object-cache drop-in bootstrap so the protected Redis secret config is loaded before connecting/authenticating.
* Propagated Redis TLS and persistent-connection settings into the generated object-cache drop-in.
* Fixed disk object-cache reads so signed payloads that contain WordPress objects can be restored instead of being rejected.
* Moved `wp_suspend_cache_addition()` handling before runtime cache mutation.

= 2.55.27 =
* Separated JS Defer and Delay controls so third-party delay, non-critical/local delay, and native defer no longer silently enable one another.
* Fixed runtime mapping so non-critical/local JS delay only runs when its own setting is enabled.

= 2.55.26 =
* Added **Critical Request Chain Relief** with manual critical-resource preloads, slider/fetch request preloads, and a chain-delay list for non-critical scripts/styles.
* Added **Asset Chain Cleanup** for WooCommerce product/gallery assets, product filter assets, and WooCommerce Blocks CSS when they are not detected as needed on the cached page.
* Fixed Frontend Safe Mode semantics so OFF really means off; automatic fragile-markup detection no longer silently overrides the user setting.
* Preserved the 2.55.25 Frontend Safe Mode, Custom Priority Image, and Main Thread Relief controls.

= 2.55.21 =
* Neutralized native `async`, `defer`, and `data-wp-strategy` attributes for protected/excluded scripts such as Revolution Slider, SR7, and `tptools` so WordPress core or third-party loading strategies cannot bypass UltraCache exclusions.
* Added a final frontend HTML safety-net pass that strips those native loading attributes from protected script tags before cache storage, covering late-emitted markup and builder output.
* Kept defer/delay exclusions authoritative across both the `script_loader_tag` path and the cached HTML rewrite path to stabilize fragile hero and slider runtimes.

= 2.55.20 =
* Expanded built-in defer and delay JS protection for Revolution Slider, SR7 runtime, Elementor frontend runtime, Bokifa theme runtime, navigation helpers, and related slider dependencies so homepage hero modules stay stable during normal navigation as well as hard reloads.
* Added broader pattern-based safety exclusions for script optimization paths touching revslider, SR7, Elementor frontend, smartmenus, header/footer Elementor, Swiper, and Bokifa runtime assets.
* Replaced the OPcache panel helper copy with production-ready operator wording focused on post-deployment opcode invalidation.

= 2.55.17 =
* Hardened Aggressive Async CSS with built-in protection for theme, builder, WooCommerce, local font, and common layout-critical stylesheet families.
* Hardened Delay Non-Critical JavaScript with built-in protection for Elementor, WooCommerce, Bokifa, navigation, slider, quick-view, search, and other interaction-critical scripts.
* Narrowed the non-critical JS matcher so experimental delay mode targets only lower-risk enhancement scripts by default.

= 2.55.15 =
* Added optional Aggressive Async CSS controls with a dedicated exclude list for broader non-blocking local stylesheet rewrites, including late footer output.
* Added optional Delay Non-Critical JavaScript controls with a dedicated exclude list for delaying selected local enhancement scripts until interaction or a short fallback timeout.
* Expanded async CSS rewriting to scan the full frontend HTML so late-emitted local stylesheet tags can also be rewritten when eligible.

= 2.55.14 =
* Added the original CSS bundle controls, inline bundle delivery, and a stylesheet exclude list for safer site-wide stylesheet targeting.
* Renamed Safe Async CSS to Async Remaining CSS and added an async CSS exclude list for finer control over non-blocking stylesheet rewrites.
* Kept the existing CSS bundle workflow, but moved it behind explicit performance toggles so aggressive behavior stays operator-controlled.

= 2.55.13 =
* Polished the media generation settings UI with clearer labels, better help text, and a compact active-mode status line.
* Restyled dashboard dropdowns for a darker in-panel look that better matches the rest of the interface.
* Kept the separate Generate on Upload, Generate on Demand, and Output Format Policy controls introduced in the previous pass.

= 2.55.04 =
* Polished fallback backend wording across admin UI and Redis fallback messages for clearer diagnostics.
* Standardized wording to use “Fallback backend” consistently instead of mixed disk/fallback phrasing.

= 2.55.02 =
* Removed the manual `load_plugin_textdomain()` call to align with modern WordPress.org translation loading expectations.
* Kept standard translation metadata (`Text Domain`, `Domain Path`, `languages/`) in place.

= 2.55.01 =
* Added the standard plugin Domain Path header and a runtime textdomain loading hook so translations can load from the plugin languages directory.
* Synced internal hotfix bundle header comments to the current release version for cleaner repository metadata.
* Cleaned repository metadata for the new 2.55.01 release series.

= 2.54.140 =
* Hardened advanced-cache path validation with realpath-aware checks before loading runtime config, runtime secret candidates, or serving cache files.
* Added an explicit serve-file readability and cache-boundary check before advanced-cache outputs a cache hit or stale response.
* Replaced the runtime secret PHP generator's var_export() call with the existing PHP string literal helper to keep the generated secret file behavior while avoiding the PHPCS development-function warning.

= 2.54.139 =
* Changed the outside-web-root runtime secret file to a per-site name derived from the site root folder, avoiding collisions when multiple sites share the same account home directory.
* Added migration fallback loading from the prior shared outside-web-root secret path and from the legacy wp-content secret path.
* Automatically removes old shared or public runtime secret files after the new per-site secret file is written successfully.

= 2.54.136 =
* Improved object-cache flush semantics and messaging. Flush results now distinguish stale residual cache entries from entries recreated after flush by live runtime activity.
* Added last object-cache flush diagnostics data so operators can inspect the most recent flush outcome from the status payload.

= 2.54.112 =
* Hardened same-host HTTPS loopbacks: UltraCache now verifies local SSL certificates first and only falls back without verification after a detected certificate-validation failure.
* Added loopback SSL fallback diagnostics so the dashboard can report when strict local SSL verification had to be bypassed temporarily.

= 2.54.111 =
* Automatically turns off Gzip/Brotli in the dashboard when frontend compression is already handled by the server/proxy, and adds a Reset Settings button beside Export/Import.
* Reduced low-value `@` error suppression in non-critical helper paths.
* Switched font CSS scan and optimization reads to safe file wrappers with internal debug logging.
* Switched Varnish admin socket connect to a safe wrapper with internal debug logging.

= 2.54.104 =
* Fixed the cron warm settings save path so `cronWarmPagesPerMinute` and `scheduledWarmLimit` no longer overwrite each other.
* Normalized `--cache-url` validation messages across WP-CLI purge, warm, and Varnish flush-url commands.

= 2.54.100 =
* Stopped the support modal from opening automatically in the admin dashboard.
* Redacted Redis and Varnish secrets from WP-CLI settings output.
* Added textdomain loading and started routing helper strings through the UltraCache text domain.
* Changed Local Google Fonts Optimization to opt-in by default for fresh installs.
* Added clearer disclosure that Google Fonts localization makes outbound requests only when enabled.

= 2.54.097 =
* Follow-up plugin-check cleanup for the query fallback helper.
* Repository follow-up cleanup for WordPress.org checks.
* Updated readme metadata and stable tag consistency.
* Reduced plugin-check findings around request sanitization and filesystem wrappers.
* Kept Varnish admin transport while documenting the remaining protocol-specific exception area.

= 2.54.087 =
* WordPress.org cleanup pass.
* Added repository-ready readme.txt metadata.
* Reduced direct filesystem fallbacks in the main runtime wrappers.
* Improved sanitization helpers and naming consistency.
* Cleaned several debug/report issues in the repository build.

== Upgrade Notice ==

= 2.55.01 =
Repository-clean release polish: textdomain loading, Domain Path metadata, and version/header cleanup.

= 2.54.111 =
Cleaner non-critical diagnostics paths with less silent suppression and safer internal debug logging.

= 2.54.104 =
Safer cron warm settings persistence plus consistent `--cache-url` validation messages.

= 2.54.100 =
Safer admin behavior, WP-CLI secret redaction, textdomain loading, and clearer opt-in handling for Google Fonts localization.

= 2.54.097 =
Repository compatibility cleanup and metadata fixes.

= 2.54.087 =
Repository cleanup and compatibility improvements for WordPress.org submission.

= 2.54.095 =
* Fixed noisy GD AVIF capability warnings when the encoder probe is unavailable while Imagick AVIF support still exists.
* Remapped legacy Varnish 6081/6082 endpoints to the site frontend HTTP origin and updated Varnish HTTP wording/UI defaults.

= 2.55.44 =
* Restored Stage 1 CSS Bundle to legacy site-wide behavior.
* Limited page-entry CSS bundle generation/replacement to Stage 2 / Aggressive mode.
