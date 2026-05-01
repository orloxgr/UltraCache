=== UltraCache ===
Contributors: orloxgr
Tags: cache, performance, redis, varnish, webp, apcu
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.56.121
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Static HTML page caching, cache warming, Redis/APCu object caching, Varnish-aware purging, AVIF/WebP optimization, CSS bundle generation, and operator-friendly diagnostics.

== Description ==

UltraCache is a production-oriented performance plugin for WordPress sites that want practical caching controls, fast warm-up tools, Redis/APCu object caching, optional Varnish integration, media conversion, CSS bundle generation, and a diagnostics-heavy admin dashboard.

= Varnish Support and object-cache compatibility =

* **Varnish Support:** optional. HTTP mode targets a local Varnish listener, for example `127.0.0.1:82`, and blocks public frontend endpoints such as `domain.com:443`. Admin mode should use localhost/private endpoints only, for example `127.0.0.1:6082`. Do not expose the Varnish admin port publicly.
* **Redis Object Cache:** recommended production backend when the PHP Redis extension and Redis service are available.
* **APCu Object Cache:** safe local fallback for single-server sites. APCu is cleared on PHP-FPM restart.
* **Disk Object Cache:** advanced/debug only. It is not recommended for production and is not used automatically as a fallback.

= Recommended backend strategy =

Object cache preference:

1. Redis
2. APCu
3. Runtime-only fallback
4. Disk only when explicitly selected for advanced/debug testing

Analytics hit backend preference:

1. APCu
2. Redis
3. Disabled when neither APCu nor Redis is available

= Main capabilities =

* Full-page HTML caching through a managed `advanced-cache.php` drop-in
* Stale-while-revalidate cache delivery
* Homepage, menu, and full-site warm-up tools
* Homepage/shared/per-page CSS bundle generation with Safe, Aggressive, and Full CSS Bundle modes
* Redis-backed object cache management
* APCu object-cache fallback for single-server setups
* Optional Varnish-aware purge workflows
* Media Optimization master switch with Auto / AVIF / WebP output policy
* Optional Google Fonts localization
* Gzip and Brotli sidecars when supported
* WooCommerce-safe bypass logic
* OPcache/APCu diagnostics cards
* WP-CLI support for operators

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate **UltraCache** through the Plugins screen in WordPress.
3. Open the **UltraCache** dashboard in wp-admin.
4. Review Diagnostics before enabling caching features.
5. Enable page cache first, then test purge and warm-up flows.
6. Enable Redis/APCu, media conversion, CSS bundles, and advanced optimizations only after validation.

== Frequently Asked Questions ==

= What should I enable first? =

Enable Page Cache first, save settings, purge all cache, warm the homepage/menu cache, then check the homepage twice and confirm the second public anonymous request can return a cache HIT.

= Does UltraCache support Varnish? =

Yes. UltraCache includes optional Varnish-aware test and purge workflows. Varnish Admin mode should remain local/private only.

= Does UltraCache support Redis and APCu? =

Yes. Redis is the recommended production object-cache backend. APCu is a safe local fallback for single-server sites. If neither Redis nor APCu is usable, UltraCache falls back to runtime-only object-cache behavior.

= Should I use Disk Object Cache? =

Only for advanced/debug testing. It is not recommended for production because it can create many small files and increase filesystem I/O.

= How does Media Optimization work now? =

Media Optimization is the master switch. Output Policy controls Auto / AVIF / WebP behavior. Actual AVIF/WebP generation depends on server Imagick/GD codec support.

= How should I handle theme-specific JavaScript errors? =

Do not hard-code theme-specific protections into the plugin. Add theme/custom script handles, filenames, or globals to **JS Delay / Defer Exclusions** manually when browser Console shows a dependency-order issue.

= What changed in the HTML rewrite refactor? =

UltraCache now prefers WordPress `WP_HTML_Tag_Processor` for many tag/attribute rewrites while keeping legacy fallbacks and safety wrappers. If a rewrite returns suspicious output, UltraCache keeps the original HTML for that response.

== Post-update checklist ==

1. Save UltraCache settings once.
2. Purge all cache.
3. Warm the homepage/menu cache.
4. Visit the homepage twice and confirm cache headers.
5. Check browser Console for JavaScript errors.
6. Check browser Network for local `/wp-content/` or `/wp-includes/` assets returning 404/403/500.
7. Test homepage, product page, cart, checkout, search, menu, sliders, fonts, and mobile layout.
8. Review Diagnostics for page cache, object cache backend, analytics backend, OPcache/APCu cards, CSS Bundle Summary, and generated drop-in versions.

== Changelog ==

= 2.56.121 =
* Regression fix: restores the dependency-aware ordered delayed-loader path for same-host scripts instead of forcing native defer for every local asset.
* Prevents grouped inline-before / inline-after configs from running out of order for integrations such as Complianz, Google Site Kit, WooCommerce and similar scripts.
* Keeps JS Delay / Defer Exclusions and hard dependency blockers as the final priority.
* Normalizes delayed script URLs to absolute public URLs while preserving the original source for diagnostics.

= 2.56.116 =
* Tightens Defer all JS into a truly aggressive mode by removing legacy conservative JS Delay / Defer exclusions when the switch is enabled.
* Keeps only the absolute dependency floor out of Defer all JS, such as jQuery and core WP globals/dependencies.
* Stops generic inline data/localization from automatically blocking Defer all JS so WooCommerce/theme scripts can be deferred when the aggressive switch is explicitly enabled.

= 2.56.115 =
* Adds a new Defer all JS toggle under Frontend JS & Request Chains for aggressive native defer on eligible scripts.
* Keeps only an absolute dependency floor out of Defer all JS, including jQuery, inline-coupled scripts, and core WP globals that commonly break when moved.
* Renames the shared visible exclusions panel to JS Delay / Defer Exclusions and applies it across defer, delay, LCP boundary defer, and related JS optimizations.

= 2.56.114 =
* Adds a visible Lazy MailerLite nonce refresh toggle in Frontend JS & Request Chains.
* Prevents MailerLite ml_create_nonce admin-ajax calls from running on page load by returning a local temporary success response.
* Refreshes the real MailerLite nonce on first form interaction or immediately before submit, then continues the normal MailerLite submit flow.
= 2.56.112 =
* Allows Advanced Runtime Font CSS Rewrite to run during slider/hero safe mode while still respecting broad Frontend Safe Mode.
* Preserves current-request original-to-optimized font CSS mappings so the runtime MutationObserver can be injected even after server-side font links have already been rewritten.
* Adds data-ucwp-font-css-map-source diagnostics to show whether the runtime map came from cache, current request, HTML links, or bundle manifest.

= 2.56.110 =
* Adds Delay icon font-face blocks, an opt-in CSS bundle feature that moves matched icon-font @font-face blocks into a delayed non-render-blocking font stylesheet.
* Adds visible/editable Delay These Fonts / Patterns and Never Delay These Fonts / Patterns fields, plus a broad icon-font auto detector.
* Adds delayed font diagnostics to CSS Bundle Summary and last CSS bundle warm reporting.

= 2.56.109 =
* Adds Full CSS Bundle as a normal CSS Bundle Mode alongside Safe and Aggressive. Full mode consolidates all eligible local stylesheet links into the generated bundle while preserving non-all media rules with @media wrappers.
* Replaces the two CSS bundle mode toggles with a single CSS Bundle Mode selector: Safe, Aggressive, and Full CSS Bundle.
* Reworked the SR7 LCP runtime helper to use the warmed/static LCP preload URL instead of repeated visible-area scans. This keeps the SR7 background LCP marker while avoiding interval-based layout measurements that showed up as forced reflow in Lighthouse/PageSpeed.
* Keeps the 2.56.106 SR7 module-background preload mapping fix intact, including generated `/revslider/o/` to real media-library source resolution.

= 2.56.105 =
* Adds debug attributes to LCP preload links so diagnostics can distinguish the plugin preload winner from the browser actual LCP request.
* Tightens the SR7 runtime helper so the scoped winner is re-evaluated repeatedly while SR7 paints module backgrounds and slider layers.
* Prefers scoped visible SR7 module backgrounds over decorative/generated RevSlider image-list assets when marking runtime LCP candidates.
* Keeps CSS bundle, warmup, purge, and defer/delay behavior unchanged.

= 2.56.100 =
* Refines SR7/Revolution Slider LCP detection so static/shared slide backgrounds are preferred over decorative rotating slide layers.
* Adds static-slide aware SR7 LCP diagnostics via data-ucwp-sr7-role, data-ucwp-lcp-reason, and data-ucwp-lcp-score markers.
* Uses the SR7 module/container visual boundary for static-slide LCP candidates instead of the DOM-last static slide node.
* Keeps CSS bundle, safe/functional third-party delay, and runtime dependency guards unchanged.

= 2.56.98 =
* Uses delaySafeThirdPartyJsEnabled as the clean safe-third-party delay switch; the old generic third-party switch is no longer used.
* Adds delayed inline companion handling so matching WordPress before/extra/after inline script blocks execute with their delayed external script in DOM order.
* Updates the delayed script loader to execute delayed inline script markers as well as delayed external scripts.
* Keeps the 2.56.97 safe/functional pattern engine and LCP Boundary Defer semantics intact while fixing functional-delay runtime dependency ordering.

= 2.56.97 =
* Renames the third-party delay UI to Delay safe third-party JS and adds Delay functional third-party JS for visible widgets such as consent/cookie scripts, captcha, maps, chat, booking, embedded forms, and reviews.
* Adds visible/editable safe third-party patterns, functional third-party patterns, and third-party delay exclusions with Populate Defaults support.
* Replaces the targeted gtag-only delay pass with a general pattern-based third-party delay engine and marks delayed tags with category reasons such as safe-third-party, functional-third-party, and lcp-boundary.
* Corrects LCP Boundary Defer behavior so it uses the detected LCP image position as the HTML boundary and delays eligible local scripts printed after that image.

= 2.56.96 =
* Adds a targeted HTML delay pass for external Google gtag.js loader scripts when Delay Third-party JS is enabled.
* Preserves visible user JS Delay / Defer Exclusions while bypassing generic inline-segment blocking only for queue-safe analytics loaders.
* Marks delayed analytics loader tags with data-ucwp-delay-reason for clearer diagnostics.

= 2.56.95 =
* Changes safe page CSS bundle application from duplicate injection to manifest-based conservative replacement, removing only bundled source stylesheet links while leaving unmatched/excluded/runtime links intact.
* Gives safe CSS bundles real request-reduction behavior without switching to the broader aggressive eligibility rules.

= 2.56.94 =
* Treats frontpage HTML + CSS warm as verified when the loopback HTTP client times out but post-warm cache inspection confirms cached HTML exists and contains the CSS bundle.
* Keeps the timeout visible in the command message while avoiding a false error exit for verified cache writes.
* Adds a warmVerifiedAfterTimeout result flag for diagnostics and REST consumers.

= 2.56.93 =
* Allows safe CSS bundle injection to run on Slider Safe Mode pages instead of building orphaned bundles that never appear in cached HTML.
* Adds final warm verification to frontpage CSS bundle builds, including bundle bytes, cached HTML bundle refs, and stylesheet link counts.
* Reports a warning/error-style result when a CSS bundle is built but the final cached HTML warm fails or does not contain the bundle reference.

= 2.56.92 =
* Adds Async Remaining CSS decision diagnostics to STORE/CSS diagnostics so applied, skipped, and unresolved stylesheet decisions show explicit reasons.
* Runs Async CSS after font CSS optimization so excluded local stylesheets can still receive font-display rewrites before async eligibility is evaluated.
* Keeps CSS Bundle Exclusions outside generated CSS bundles while still allowing eligible excluded local stylesheets to pass through self-hosted font CSS optimization.
* Allows Async Remaining CSS and Aggressive Async CSS to run even when slider-safe mode is enabled; CSS Bundle Exclusions no longer suppress those passes.
* Fixes font-display injection so minified @font-face blocks receive a valid semicolon before `font-display: swap`.

= 2.56.89 =
* Changes CSS diagnostics source actions from copy-only to Append exclusion line inside the visible CSS Bundle Exclusions editor.
* Deduplicates appended CSS exclusion lines against the current textarea draft and marks covered suggestions as Already in exclusions.
* Clarifies that CSS Bundle Exclusions keep matching stylesheets outside generated bundles and loaded normally as original stylesheet links.
* Keeps CSS exclusion actions manual: diagnostics do not automatically save or apply exclusions until the user saves the editor.
* Preserves the 2.56.88 Advanced Settings layout with CSS Bundle Exclusions and JS Delay / Defer Exclusions at the bottom of Advanced Settings & Exclusions.

= 2.56.84 =
* Polishes JS Delay Scan results into live-count sections: Missing recommended, Already listed recommended, and Review-only detected.
* Recomputes missing/already-listed counts from the current JS Delay / Defer Exclusions textarea so the header and append button cannot show stale missing values after populate/append/manual edits.
* Renames the append action to Append Missing Recommended and only enables it when appendable scan results are missing from the visible textarea.
* Shows Missing, Already listed, Recommended, Detected, and Review-only counters so users can see exactly where missing exclusions are.


= 2.56.80 =
* Adds a full-width JS Delay / Defer Exclusions panel with Populate Defaults, Scan Latest Profile, Append New Suggestions, and Save controls.
* Adds JS Delay Safety Scan diagnostics to STORE profiles for inline handler/global dependency breaks caused by delayed scripts, with high-confidence suggested visible exclusions and duplicate-safe appending.

= 2.56.77 =
* Refines Fix sliders / hero sections asset protection so generic words like `slider`, `carousel`, `slideshow`, and `hero` are no longer used for URL/handle protection. This avoids false-positive protection for non-hero assets such as product-filter range sliders while keeping broad generic terms for markup detection only.

= 2.56.76 =
* Adds Critical Request Chain diagnostics to the Performance Profiler, showing render-blocking CSS/JS candidates, delayed/protected script status, protection reasons, origins, locations, and suggested next actions without changing runtime loading behavior.

= 2.56.75 =
* Adds visible suggested CSS Bundle Exclusion lines for top CSS bundle sources in the Performance Profiler source list.
* Adds copy-to-clipboard controls for suggested exclusion lines so operators can move heavy sources to the editable CSS Bundle Exclusions field after visual testing. Runtime CSS loading behavior is unchanged.

= 2.56.74 =
* Adds CSS bundle critical-path diagnostics to the Performance Profiler summary, including bundle bytes, source stylesheet bytes, largest source, top CSS source contributors, and render-blocking stylesheet counts.
* Adds large CSS bundle warnings when the generated bundle crosses diagnostic thresholds. Runtime CSS loading behavior is unchanged.

= 2.56.73 =
* Replaces site-specific placeholder examples in Advanced Settings textareas with generic plugin-safe examples.
* Updates Manual LCP Hero / Slider selector copy so placeholders do not reference the development/test site. Runtime behavior is unchanged.

= 2.56.72 =
* Maps selected local LCP preload candidates to existing one-to-one UltraCache AVIF/WebP equivalents when media optimization is enabled, so preload targets match optimized runtime image rewrites.
* Keeps same-origin optimized image preloads free of `crossorigin` and emits the matching image MIME `type` hint.

= 2.56.71 =
* Adds a generic LCP candidate scoring engine inside the visible Manual LCP Hero / Slider selector scope, covering img/srcset, SR7 attributes, inline `background-image`, and shorthand `background: url(...)` sources.
* Prefers rendered high-confidence hero/background URLs, including actual AVIF/WebP URLs present in the HTML, instead of relying on the first marked SR7 image only.
* Removes `crossorigin` from same-origin image preloads and adds image MIME `type` hints so CSS background preloads can be reused by the browser without credentials-mode mismatch warnings.

= 2.56.70 =
* Adds a visible Manual LCP Hero / Slider selector field for high-confidence hero/slider targeting, accepting generic entries like `#main-hero`, `homepage-slider`, or `.hero-slider`.
* Uses a found manual hero/slider selector as the preferred SR7 LCP preload scope so the first marked candidate in that block drives the preload target.
* Keeps SR7 runtime LCP priority, slider-aware Boundary Defer, protected slider script exclusions, and HTTPS-safe local Google Fonts URLs unchanged.

= 2.56.68 =
* Normalizes UltraCache local Google Fonts URLs inside generated CSS to root-relative `/wp-content/cache/ultracache/google-fonts/...` paths so HTTPS pages do not request blocked `http://` font assets.
* Rewrites existing local Google Fonts CSS cache files when reused, so old cached `http://` font URLs are corrected without deleting the downloaded font binaries.

= 2.56.67 =
* Keeps SR7/Revolution/Swiper/Slick runtime assets protected while boundary defer remains slider-aware and conservative.
* Updates admin copy to explain that Fix sliders / hero sections is a protection layer, not a replacement for LCP Image Priority.

= 2.56.66 =
* Adds an admin beforeunload guard while queued settings, save requests, dashboard actions, or long admin processes are still running.
* Keeps queued/running dashboard action toasts persistent until success or error replaces the same notice.
* Improves critical cache setting save UX so cache-impacting toggles stay visibly queued/saving and dashboard actions wait for the save to finish.

= 2.56.65 =
* Removes stray Google Fonts preconnect/dns-prefetch hints after CSS Aggressive Bundling folds the original Google Fonts stylesheet into the generated external bundle.
* Keeps the 2.56.64 aggressive fallback cleanup intact: no per-original noscript fallback links are restored.

= 2.56.64 =
* Stops CSS Aggressive Bundling from adding per-original noscript fallback links after replacing matched local stylesheets with the generated bundle.
* Adds Performance Profiler CSS output diagnostics for final HTML size, inline CSS bytes, fallback link/marker counts, and noscript count.
* Adds a visible profiler warning when Inline CSS Bundling creates large cached HTML, without silently overriding the user's inline setting.

= 2.56.63 =
* Corrects the saved Performance Profiler summary mode so callback profiler results show as callback in the last-profile endpoint and UI instead of looking like compact-only profiles.
* Improves the profiler help text to explain when the tool is useful and what question each run type answers.
* Shows up to 12 plugin/theme/core timing groups and improves mobile wrapping for profiler action buttons.

= 2.56.62 =
* Converts the wp-admin Performance Profiler into a collapsed accordion to keep the dashboard cleaner.
* Improves the profiler helper copy so it explains when the tool is useful instead of describing internal mechanics.
* Adds a Plugin / Theme Time Summary that aggregates callback profiler time by plugin, theme, and WordPress core.
* Adds dedicated spacing for the profiler action buttons so the controls no longer appear stuck together.

= 2.56.61 =
* Adds a manual wp-admin Performance Profiler card with Compact STORE, Verbose STORE, and Callback Profiler buttons.
* Runs profiler requests only through explicit admin actions with capability/nonce-protected REST calls; no persistent frontend profiling is enabled.
* Adds summary-first profiler output in the dashboard plus an on-demand JSON download/clear workflow.

= 2.56.60 =
* Makes the default STORE profile compact by recording only key lifecycle/rewrite checkpoints; full checkpoint output is available only with X-UltraCache-Store-Profile-Verbose: 1 or ?ucwp_store_profile_verbose=1.
* Removes automatic hook_summary expansion from normal STORE profiles; hook callback summaries are now verbose-only.
* Keeps manual callback profiling separate and explicit via the existing callback profiler trigger, without leaking into normal STORE/HIT requests.

= 2.56.59 =
* Cleans up the temporary wide init/debug profiler checkpoints from the STORE profiler while keeping the manual callback profiler available only when explicitly requested.
* Limits the diagnostic X-Ultra-Cache-Source header to explicit debug requests via X-UltraCache-Debug: 1, reducing normal production header noise.
* Keeps the 2.56.58 runtime-config resync after full purge so advanced-cache HIT delivery remains active immediately after purging.

= 2.56.58 =
* Rebuilds the runtime-config sidecar immediately after full purge so the next anonymous HIT can be served by the advanced-cache drop-in instead of falling back to the later WordPress engine early-hit path.
* Adds an explicit X-Ultra-Cache-Source response header for advanced-cache vs WP-engine HIT diagnostics.

= 2.56.57 =
* Makes deep callback profiling fully manual/opt-in via X-UltraCache-Callback-Profile: 1 or ?ucwp_callback_profile=1, and only when STORE profiling is also enabled.
* Avoids registering diagnostic lifecycle/callback wrappers on normal frontend requests, protecting regular STORE/HIT performance.
* Treats profiler query args as diagnostic-only so they do not force query-arg cache bypass in WordPress cacheability checks.

= 2.56.56 =
* Moves object-cache support checks in frontend settings sanitization to cached/read-only mode while preserving live checks in privileged/save flows.
* Enriches guarded profiler slow-callback checkpoints with callback, origin/plugin, file, hook, and priority fields.
- 2.56.55: Adds guarded callback timing profiler for wp_enqueue_scripts, template_redirect, loader filters, srcset, and shutdown; repetitive settings checkpoints are verbose-only.
- 2.56.54: Skips live frontend compression loopback probes during normal settings/default sanitization; cached/browser diagnostics remain available.
- 2.56.53: Adds an opt-in early buffer/cacheability micro-profiler around maybe_start_buffering(), should_bypass_cache(), settings loading, and support-probe sanitization to isolate the remaining pre-early-HIT STORE delay.
- 2.56.40: Hardens the 2.56.39 Google Fonts admin-scan workflow by making legacy live-build queues/events cleanup-only, keeping frontend missing-font handling read-only, and preserving the page-cache stampede lock behavior.
- 2.56.39: Adds page-cache stampede protection for cold concurrent requests, keeps Google Fonts rebuilding manual/admin-controlled without frontend live builds, and avoids leaving server-cron-only Google Fonts rebuild events after settings save.
- 2.56.38: Moves Local Google Fonts Optimization to a controlled admin/save/manual-rebuild scan pipeline, stops frontend Google Fonts build/queue behavior, adds Additional URLs for scanning, and preserves the Google Fonts cache during Flush All Cache.
- 2.56.36: Keeps the 2.56.35 canonical Google Fonts fix, but frontend/loopback requests now only reuse existing local font CSS and queue missing fonts for WP-CLI/server cron without synchronous downloads.
- 2.56.34: Coalesced Google Fonts background builds into a single queue/runner and made the runtime self-hosted font CSS map non-blocking on frontend requests.

= 2.56.55 =

* Added guarded callback timing summaries to STORE profile diagnostics for wp_enqueue_scripts, template_redirect, style/script loader filters, wp_calculate_image_srcset, and shutdown.
* Made repetitive settings getter checkpoints opt-in via ucwp_store_profile_verbose_settings=1, keeping normal diagnostic JSON smaller.

= 2.56.54 =

* Skips live frontend compression loopback probes during normal settings/default sanitization; cached/browser diagnostics remain available.

= 2.56.53 =

* Added opt-in early buffer/cacheability micro-profiling, limited to profiled requests, to isolate the remaining maybe_start_buffering → early_hit_check delay.
* Added checkpoints around should_bypass_cache(), engine settings loading, dashboard settings sanitization, and heavy support probes while preserving frontend behavior.
* Kept the opt-in STORE and deep request lifecycle profiler for rewrite-stage bytes/timings plus checkpoints across plugin load, dependencies, plugins_loaded priorities, setup_theme, after_setup_theme, init, wp_loaded, template_redirect, wp_head, output callback, cache write, and shutdown.
* STORE profiler can be triggered with the X-UltraCache-Store-Profile: 1 request header or ?ucwp_store_profile=1; the diagnostic query arg is stripped from the cache key.
* Added wp ultracache store_profile show --format=json and wp ultracache store_profile clear.
* Added CSS bundle diagnostics for user-controlled Inline CSS Bundling, including more robust inline style byte scanning, external bundle links, fallback counts, and manifest bundle file size.
* Added Populate Defaults buttons for visible safeguard/exclusion lists without forcing a full settings reset.
* Preserved the 2.56.43 Google Fonts architecture: no frontend live builds, no legacy queue recreation, no google-fonts-pending, and purge-all still preserves local Google Fonts cache.

= 2.56.40 =
* Frontend Google Fonts rewriting is read-only when local files are missing: it keeps the original Google Fonts URL and never creates legacy live-build queue data.
* Kept the validated 2.56.39 page-cache stampede lock behavior and the controlled dashboard/WP-CLI rebuild workflow.

= 2.56.39 =
* Added cold page-cache generation stampede protection so concurrent first hits wait for the first generated HTML cache instead of all rendering/storing independently.
* Settings save no longer depends on WP-Cron for Google Fonts cache rebuild; dashboard button/WP-CLI remain the controlled build paths.

= 2.56.38 =
* Local Google Fonts Optimization no longer discovers, queues, downloads, or builds Google Fonts assets from live frontend requests. Frontend HTML only rewrites Google Fonts links when the local CSS file already exists; otherwise it keeps the original Google Fonts URL intact.
* Enabling Local Google Fonts Optimization queues a homepage scan through the admin/server-cron path, not through the frontend request path.
* Added Advanced Settings & Exclusions -> Additional URLs for Google Fonts scanning with Save Google Fonts URLs and Rebuild Google Fonts Cache controls.
* Rebuild Google Fonts Cache scans the homepage plus configured local URLs, clears/rebuilds only the Google Fonts cache, and downloads the discovered CSS/WOFF assets under wp-content/cache/ultracache/google-fonts/.
* Flush All Cache now preserves wp-content/cache/ultracache/google-fonts/ so local font files are not thrown away during normal page-cache purges.

= 2.56.36 =

* Kept the 2.56.35 canonical Google Fonts URL/hash behavior.
* Frontend and internal loopback requests now only reuse already-built local Google Fonts CSS; missing font CSS is queued for the real cron/WP-CLI runner instead of being downloaded during the page request.
* Added a short schedule lock so cold concurrency does not create duplicate Google Fonts build events.
* Left original Google Fonts URLs as fallback until the local files exist, so CSS integrity is preserved.

* Fixed the protocol-relative Google Fonts root cause where //fonts.googleapis.com/... and https://fonts.googleapis.com/... were treated as different hashes.
* Prevented google-fonts-pending from blocking page-cache storage; local font generation now stays best-effort and the frontend keeps the valid Google Fonts fallback.
* Kept the 2.56.34 baseline behavior and did not carry over the later experimental frontend CSS branches.

= 2.56.34 =
* Coalesced Google Fonts background builds into a single queue/runner.
* Made the runtime self-hosted font CSS map non-blocking on frontend requests.

= 2.56.28 =
* Added single-flight transient locks for Google Fonts CSS and font binary localization to prevent frontend PHP-FPM worker floods during cold cache generation.
* Google Fonts remote requests now use shorter timeouts and fall back to the original Google URLs while another request is already building the local cache.
* Replaced the cron warm-up schedule scan with wp_next_scheduled() to avoid walking the full WP-Cron array on dashboard/settings loads.

= 2.56.27 =
* Added hard single-flight locks for heavy dashboard actions and CSS/frontpage bundle generation.
* Stale dashboard action jobs are now failed automatically instead of blocking future actions.
* Internal loopback requests now carry UltraCache headers so on-entry CSS generation does not recursively amplify PHP workers.
* Pruned dashboard action queue storage to avoid stale running jobs and oversized options.

= 2.56.26 =
* Linked the Diagnostics and Activity Summary accordions so opening or closing either card toggles both together.
* Added the missing .text-right utility rule so status pills and right-aligned diagnostic text render correctly.

= 2.56.25 =
* Added the Query-string args whitelist Populate action for WooCommerce/taxonomy query keys.

= 2.56.24 =
* Stabilized page-cache variant creation: query-string HTML cache variants now require an explicit allowlist.
* Added a safety cap for same-path/same-bucket HTML variants to prevent runaway homepage cache files.
* Fixed Diagnostics and Activity Summary visibility so they remain visible when Cache Stats is disabled.

= 2.56.23 =
* Changed performance profile patches so no profile enables background/scheduled warm-up.
* Aggressive profile now keeps cronWarmEnabled, cronWarmStartAfterCleanup, and cronWarmStartAfterManualPurge disabled.
* Manual warm-up buttons remain unchanged.

= 2.56.21 =
* Blocked unsafe Varnish HTTP endpoints that point to the public WordPress frontend, especially domain.com:80, domain.com:443, and unsupported HTTP-mode ports.
* Changed the Varnish HTTP default endpoint to 127.0.0.1:82; Admin mode remains 127.0.0.1:6082.
* Added runtime guards so Varnish Test, Flush All, and URL purge refuse unsafe HTTP endpoints even if old options or imports contain them.
* Added diagnostics for old W3 Total Cache / Varnish helper leftovers before enabling Varnish or Object Cache.

= 2.56.19 =
* Consolidated version reporting to a single UCWP_VERSION source and removed the private hotfix bundle duplicate.
* Removed legacy REST namespace registration so only ultracache/v1 is exposed.
* Removed old runtime secret path fallback loading and kept only the per-site secret file outside the webroot.
* Removed the old WP_CACHE marker normalization path; the current managed block is the only supported marker.

= 2.56.18 =
* Simplified Varnish HTTP endpoint handling to the current host:port-only model.
* Removed the legacy Varnish endpoint remap that silently changed old Varnish listener ports to the detected frontend endpoint.
* Added an admin-mode fallback endpoint of 127.0.0.1:6082 when admin mode is selected and the endpoints field is empty.

= 2.56.17 =
* Clarified the Varnish HTTP UI: endpoints are documented as host:port only, and PURGE no longer claims an automatic BAN fallback.
* Aligned the advanced-cache fallback defaults with the dashboard defaults for Woo safe mode and stale-while-revalidate.
* Fixed the runtime defer_stage_safe mapping so the computed safe-stage flag is written to config.

= 2.56.15 =
* Fixed a dashboard crash in Advanced Diagnostics caused by an undefined objectFallbackActive variable.
* Canonicalized saved dashboard settings on load so invalid previous combinations like Slider Safe Mode + LCP Boundary Defer are written back as valid settings.
* Restricted data-ucwp-sr7-lcp to SR7/RevSlider image candidates instead of allowing normal site images, such as the logo, to receive SR7-specific markers.
* Added generic data-ucwp-lcp markers for non-slider LCP image candidates.

= 2.56.09 =
* Hoisted CSS @import rules to the top of generated page CSS bundles so browser import ordering remains valid.
* Preserved one CSS @charset rule at the top of the bundle when source stylesheets include one.
* Rewrote relative @import URLs and normal url(...) references against their original stylesheet URL before bundling.
* Added an HTML Content-Type guard before warm_url writes static HTML cache files or scans HTML for CSS bundling.
* Reduced per-page CSS bundle warmups from warm -> bundle -> warm to bundle -> warm, avoiding the extra final loopback pass.

= 2.56.07 =
* Updated Warm Cache button labels to reflect the selected CSS Bundling Scope: Homepage CSS Bundle, Shared CSS Bundles, or Separate CSS Bundles.
* Menu/full-site CSS warm actions now do what their labels say: homepage/shared scopes build the homepage/shared CSS bundle once, while per-page scope builds separate CSS bundles per warmed URL.
* Kept HTML-only warm buttons separate from CSS bundle warm buttons.

= 2.56.06 =
* Scoped the WordPress admin #wpcontent padding-left override to the UltraCache dashboard only.
* Removed the default WordPress left gutter from the UltraCache full-background admin UI without affecting other wp-admin pages.

= 2.56.05 =
* Added an Advanced Settings master switch: LCP Optimization.
* Gated LCP Image Priority, manual LCP image URL, and LCP Boundary Defer behind the new master switch while keeping backward compatibility for existing enabled installs.
* Added SR7/Revolution Slider generated-image discovery for hashed /revslider/o/ assets, preferring existing UltraCache AVIF/WebP cache variants and using the detected SR7 module boundary for LCP Boundary Defer.
* Kept LCP Boundary Defer conservative: core/plugin protections, dependencies, sliders, WooCommerce, Elementor, and manual exclusions stay protected.

= 2.56.02 =
* WP-CLI: `wp ultracache purge --all` is now accepted as an explicit full-cache purge alias for `wp ultracache purge`.

= 2.55.81 =
* Changed analytics hits to APCu -> Redis -> disabled.
* Changed object cache strategy to Redis -> APCu -> runtime-only, with Disk only as explicit advanced/debug mode.


== Upgrade Notice ==

= 2.56.36 =

* Kept the 2.56.35 canonical Google Fonts URL/hash behavior.
* Frontend and internal loopback requests now only reuse already-built local Google Fonts CSS; missing font CSS is queued for the real cron/WP-CLI runner instead of being downloaded during the page request.
* Added a short schedule lock so cold concurrency does not create duplicate Google Fonts build events.
* Left original Google Fonts URLs as fallback until the local files exist, so CSS integrity is preserved.

Fixes protocol-relative Google Fonts hash mismatches and stops pending local font builds from blocking page-cache storage.

= 2.56.27 =
Heavy dashboard actions and CSS/frontpage bundle generation are now single-flight locked to prevent PHP worker floods from stale or parallel dashboard jobs.

= 2.56.26 =
Diagnostics and Activity Summary accordions now toggle together, and the missing text-right utility is available for right-aligned status text.

= 2.56.23 =
Performance profiles no longer enable background/scheduled warm-up. Manual warm-up buttons remain unchanged.

= 2.56.21 =
Varnish self-endpoint guard workflow build. Public frontend endpoints are blocked for Varnish HTTP purge.

= 2.56.18 =
Varnish endpoint simplification workflow build. HTTP mode is host:port only; admin mode defaults to 127.0.0.1:6082 if blank.

= 2.56.17 =
Varnish UI truthfulness and low-risk runtime fallback cleanup workflow build.

= 2.56.09 =
CSS bundling correctness build. Hoists @import/@charset correctly, adds HTML Content-Type warm guards, and reduces duplicate per-page CSS warm loopbacks.

= 2.56.07 =
Varnish admin/BAN diagnostics and object cache backend truth reporting build.
