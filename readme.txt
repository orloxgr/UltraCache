=== UltraCache ===
Contributors: orloxgr
Tags: cache, performance, redis, varnish, webp
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.57.134
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

WordPress page cache, object cache, media optimization, Varnish purge tools, warm-up, and performance diagnostics.

== Description ==

UltraCache is a WordPress performance plugin for site owners and operators who want practical caching controls without hiding what the plugin is doing.

It can cache public HTML pages, warm important URLs, connect to object-cache backends, help localize fonts, generate AVIF/WebP images, build CSS bundles, and show diagnostics for the cache stack. The goal is to make a WordPress site faster while keeping the risky parts visible and controllable.

= What UltraCache helps with =

* Serve anonymous public pages from static HTML cache.
* Reduce repeat WordPress/PHP work with object cache support.
* Warm homepage, menu URLs, and full-site HTML cache.
* Generate AVIF/WebP variants when the server image stack supports them.
* Optimize Google Fonts and self-hosted font CSS.
* Build CSS bundles and show what was bundled, skipped, or unresolved.
* Add LCP image priority hints and slider/hero support.
* Delay/defer eligible JavaScript with visible exclusion controls.
* Integrate with Varnish purge workflows when a site is behind Varnish.
* Show diagnostics for page cache, object cache, storage, Varnish, OPcache/APCu, media conversion, CSS bundles, and request chains.

= Supported caching and performance technologies =

* **HTML page cache:** managed through `advanced-cache.php` and files under `wp-content/cache/ultracache/`.
* **Redis object cache:** recommended persistent object-cache backend when the PHP Redis extension and Redis service are available.
* **APCu object cache:** local single-server fallback when Redis is unavailable.
* **Runtime-only object cache:** safe fallback when persistent object cache is unavailable.
* **Disk object cache:** explicit advanced/debug option only; not recommended as the default production backend.
* **Varnish:** optional HTTP endpoint or admin-secret integration for testing and purging reverse-proxy cache.
* **OPcache:** diagnostics and visibility for the PHP opcode cache; UltraCache does not replace PHP OPcache.
* **Server compression:** detects server gzip/Brotli behavior and avoids unnecessary duplicate compression where applicable.
* **AVIF/WebP:** media conversion depends on Imagick/GD codec support on the server.
* **Google Fonts/local fonts:** local Google Fonts cache and self-hosted font CSS optimization tools.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP from the WordPress admin.
2. Activate **UltraCache** from the Plugins screen.
3. Open the **UltraCache** dashboard.
4. Review Diagnostics before enabling heavy features.
5. Enable features gradually and test the frontend after each important change.

== Quick Start Guide ==

= 1. Start with a safe profile =

1. Open UltraCache in wp-admin.
2. Choose a safe/baseline profile.
3. Enable **Page Cache**.
4. Save settings.
5. Purge all cache.
6. Warm the homepage or menu cache.
7. Visit the homepage twice as a logged-out visitor and confirm that cache headers can show a HIT.

= 2. Configure object cache =

1. Open the Object Cache section.
2. Prefer Redis if available.
3. Enter Redis host, port, password/database/TLS/persistent settings when needed.
4. Test the connection.
5. If Redis is not available, use APCu when supported.
6. Use Disk only for advanced/debug testing.

= 3. Check the profiler and diagnostics =

Use Speed Diagnostics / profiler tools when a page is slow on MISS/STORE or when HTML rewrites appear expensive. Review server timing, STORE profile timing, frontend rewrite stages, CSS Bundle Summary, object-cache backend truth, Varnish/reverse-proxy status, storage diagnostics, and media queue health.

= 4. Tune CSS safely =

1. Start with Safe CSS Bundle mode.
2. Warm the homepage with CSS bundle generation.
3. Review CSS Bundle Summary.
4. Add exclusions for stylesheets that break layout or should not be bundled.
5. Test homepage, product pages, cart, checkout, search, menu, sliders, fonts, and mobile layout.
6. Use more aggressive CSS options only after visual testing.

= 5. Tune JavaScript safely =

1. Use visible JS Delay / Defer Exclusions for scripts that must keep their order.
2. Do not blindly defer jQuery, WooCommerce core dependencies, Elementor runtime, or active above-the-fold slider scripts.
3. Check the browser Console after every JS defer/delay change.
4. Keep site-specific safeguards visible and editable.

= 6. Optimize media and fonts =

1. Enable Media Optimization only when AVIF/WebP support is confirmed.
2. Run or resume batch conversion from the AVIF/WebP Batch Conversion box.
3. Use Local Google Fonts Optimization for remote `fonts.googleapis.com` stylesheets.
4. Use Self-hosted Font CSS Optimization for existing local/theme/Elementor font CSS.
5. Rebuild Google Fonts cache after changing scan URLs.

== Public Defaults and Experimental Features ==

UltraCache is designed to start conservatively. Riskier optimization controls are intentionally off by default or marked as advanced/experimental in the dashboard.

Experimental or advanced controls include Full CSS Bundle, Aggressive CSS Bundle mode, Aggressive Async CSS, and Advanced Runtime Font CSS Rewrite. Enable these only after testing important pages, cart/checkout/account flows, sliders, forms, menus, fonts, and browser Console output.

UltraCache keeps safety lists visible in Advanced Settings & Exclusions so site owners can review and edit exclusions instead of relying on hidden hard-coded site rules.

== External Services ==

UltraCache stores generated cache files on the local WordPress installation under `wp-content/cache/ultracache/` and optimized image variants under `wp-content/uploads/uc-images/`. The basic page cache, object cache, media conversion queue, CSS/JS optimization, Varnish integration, and diagnostics do not require an external SaaS account.

= Google Fonts =

When Local Google Fonts Optimization is enabled by an administrator, UltraCache may request Google Fonts CSS and font files from `fonts.googleapis.com` and `fonts.gstatic.com` while building or rebuilding the local Google Fonts cache. This is used to download the CSS/font files and store local copies under `wp-content/cache/ultracache/google-fonts/`.

These requests are made only from the WordPress server during an administrator-initiated build/rebuild, cache warm process, or Google Fonts cache refresh that needs the local font cache. UltraCache does not require a Google account or API key.

Data sent: the requested Google Fonts CSS/font URL and normal HTTP request data such as the server IP address, user agent, and request headers.

Service provider: Google Fonts / Google LLC.
Google Fonts Privacy FAQ: https://developers.google.com/fonts/faq/privacy
Google Terms of Service: https://policies.google.com/terms

= Existing third-party scripts on the site =

UltraCache may detect URLs or inline code from services such as Google Tag Manager, Google Analytics, Facebook/Meta Pixel, Google Maps, reCAPTCHA, hCaptcha, Hotjar, Microsoft Clarity, Stripe, PayPal, and similar services when analyzing the site's existing frontend HTML for delay/defer exclusions, cache safety rules, or diagnostics. UltraCache does not add these services to the site and does not send data to them by itself.

= Varnish =

Varnish integration is optional and administrator-configured. If configured, UltraCache may send purge or test requests to the Varnish endpoint selected by the site administrator, including external infrastructure endpoints when intentionally configured. Varnish admin secrets are treated as runtime secrets and should never be exposed to frontend HTML, JavaScript, REST responses, or logs.

= Support and donation links =

The UltraCache dashboard includes optional support/donation links. Clicking a PayPal support button opens PayPal in the administrator's browser. UltraCache does not send site visitor data to PayPal. If an administrator clicks a PayPal link, PayPal receives the normal browser request data for that visit.

Service provider: PayPal.
PayPal Privacy Statement: https://www.paypal.com/privacy
PayPal User Agreement: https://www.paypal.com/legalhub/useragreement-full

== WP-CLI Commands ==

Use WP-CLI as the site owner, not with `--allow-root`, when possible.

* `wp ultracache cleanup` - run scheduled cleanup.
* `wp ultracache cron_warm` - inspect or process the cron warm queue.
* `wp ultracache css_diagnostics` - show CSS bundle/request-chain diagnostics.
* `wp ultracache flush_object_cache` - flush the managed object cache.
* `wp ultracache google_fonts_rebuild` - rebuild the local Google Fonts cache.
* `wp ultracache inspect` - inspect cacheability for a local URL.
* `wp ultracache media` - manage AVIF/WebP media conversion queue.
* `wp ultracache purge` - purge full cache or a local URL.
* `wp ultracache self_test` - run internal smoke/self checks.
* `wp ultracache settings` - read or update dashboard settings.
* `wp ultracache stats` - read or reset cache statistics.
* `wp ultracache status` - show cache/drop-in/storage status.
* `wp ultracache store_profile` - show or clear the last STORE profile.
* `wp ultracache varnish` - test or trigger Varnish helpers.
* `wp ultracache warm` - warm cache URLs.
* `wp ultracache warm_frontpage_html` - warm homepage HTML cache.
* `wp ultracache warm_frontpage_html_css` - warm homepage HTML cache plus CSS bundle.
* `wp ultracache warm_html_all` - warm full-site HTML cache.
* `wp ultracache warm_html_all_css` - warm full-site HTML cache plus CSS bundles.

Common examples:

`wp ultracache status --format=json`
`wp ultracache status --section=storage --format=json`
`wp ultracache purge --all`
`wp ultracache purge --cache-url=https://example.com/`
`wp ultracache warm_frontpage_html_css`
`wp ultracache media status --media-format=both --format=json`
`wp ultracache media rebuild --media-format=both --format=json`
`wp ultracache media process --media-format=both --format=json`
`wp ultracache google_fonts_rebuild --clear`
`wp ultracache flush_object_cache`
`wp ultracache store_profile show --format=json`

== Frequently Asked Questions ==

= What should I enable first? =

Enable Page Cache first. Save settings, purge all cache, warm the homepage/menu cache, then check the homepage twice as a logged-out visitor.

= Does UltraCache support Varnish? =

Yes. Varnish support is optional. Use explicitly configured endpoints only. External infrastructure endpoints are supported when firewalled; do not expose the Varnish admin port publicly.

= Does UltraCache support Redis and APCu? =

Yes. Redis is the preferred persistent object-cache backend. APCu is a local single-server fallback. If neither is available, UltraCache falls back safely to runtime-only object-cache behavior.

= Should I use Disk Object Cache? =

Only for explicit advanced/debug testing. Disk object cache can create many small files and is not recommended as the normal production backend.

= Does UltraCache replace OPcache? =

No. OPcache is a PHP runtime feature. UltraCache can show diagnostics related to OPcache, but it does not replace or manage PHP OPcache itself.

= Why do I still see MISS/STORE after a purge? =

A manual purge removes fresh cache. The next uncached anonymous request may have to render and store a new page unless warm-up has already rebuilt that URL.

= How do I fix layout or JavaScript breakage? =

Disable the last risky optimization, confirm the issue disappears, then add visible exclusions for the specific CSS or JS file/handle/global that needs protection.

== Post-update checklist ==

1. Save UltraCache settings once.
2. Purge all cache.
3. Warm the homepage/menu cache.
4. Visit important pages as a logged-out user.
5. Check cache headers.
6. Check browser Console for JavaScript errors.
7. Check browser Network for missing local assets.
8. Test homepage, product page, cart, checkout, search, menu, sliders, fonts, and mobile layout.
9. Review Diagnostics for page cache, object cache, Varnish, storage, media queue, CSS Bundle Summary, and generated drop-in versions.

== Changelog ==

= 2.57.134 =
* Stops frontend/on-demand media generation from creating automatic pending AVIF/WebP/Both background queue rows. Missing variants remain for explicit bulk or WP-CLI conversion.
* Retires old partial on-demand queue rows so they no longer keep `ucwp_process_media_conversion_queue` due-now.
* Limits the automatic background media hook to the `best` upload queue with a small batch/time budget; manual UI bulk and WP-CLI media conversion remain unrestricted.

= 2.57.133 =
* Removed the hidden automatic safe-html-minify pass from frontend/cache HTML output.
* Removed the removed minify stage from profiler labels and updated the safe frontend mode UI wording.
* Keeps stylesheet de-duplication and the recent JS/runtime-scan fixes unchanged.

= 2.57.131 =
* Keeps WordPress attached inline scripts with their parent handle decision so `-js-before`/`-js-after` config blocks are not delayed standalone while their parent script remains parser-loaded.
* Filters console-paste suggestions so harmless console output such as JQMIGRATE notices and Complianz opt-in messages are not treated as runtime errors.

= 2.57.130 =
- Normalizes bad local `@font-face` values such as `font-display: block` and `font-display: auto` to `font-display: swap` while preserving all existing `src()` entries.

= 2.57.129 =
- Preserves existing `@font-face` blocks and normalizes relative font URLs when writing cached CSS copies.

= 2.57.128 =
- Adds a final UltraCache stylesheet de-duplication pass so generated/injected CSS, delayed font-css links, and async CSS noscript fallbacks are emitted once per href.
- Keeps one async link and one noscript fallback per generated CSS href.
- Does not change async eligibility, font-display normalization behavior, or theme/layout CSS handling.

= 2.57.127 =
- Adds a central server-side font-display normalization layer for local linked stylesheets, inline `@font-face` CSS, generated CSS bundles, optimized-css assets, font-css assets, delayed-font CSS, and localized Google Fonts CSS.
- Adds `font-display: swap` only when a `@font-face` block is missing `font-display`, so changing CSS Bundling or Async CSS settings should not reintroduce the same missing font-display audit through another CSS path.
- Writes normalized mixed/layout stylesheets to `optimized-css/active-font-display-*.css` with visible markers while preserving the stylesheet as blocking/render-path, avoiding another async/layout experiment.

= 2.57.125 =
- Extends Async Remaining CSS to UltraCache-generated external CSS links, including page/frontpage CSS bundles, leftover bundles, optimized-css files, and delayed/font CSS files.
- Generated CSS links now keep noscript fallbacks but can load with the existing print+onload async strategy when the existing Async CSS switch is enabled.
- Adds data-ucwp-generated-css-async markers and clearer async CSS diagnostic reasons for generated bundle, leftover, optimized, and delayed font CSS.

= 2.57.124 =
- Extends Media Optimization to generated/local CSS delivery by rewriting eligible local upload background-image URLs inside CSS bundles and optimized CSS.
- Automatic media mode now emits original fallback declarations plus `image-set()` optimized candidates for CSS backgrounds, so modern browsers can choose AVIF/WebP while older browsers keep the original image.
- Adds CSS background image rewrite counters to CSS bundle stats/source details.

= 2.57.123 =
* Delayed JS loader: tracking/third-party delayed scripts now auto-run after window load unless the visitor interacts first. Local/non-third-party delayed scripts can still run after DOMContentLoaded.
* Added delayed-loader diagnostic markers for queued/local/third-party counts and run states.
* Bounded runtime font-display CSSOM patching with capped stylesheet/rule scans and a short-lived observer.
* Bounded the advanced runtime font CSS rewrite observer to reduce frontend main-thread pressure on large pages.

= 2.57.122 =
* Font Display Swap now routes same-origin theme/plugin stylesheet links through preserved optimized-css copies when their @font-face blocks are missing font-display.
* Keeps non-font CSS rules intact while preventing Lighthouse/Chromium from still seeing the original missing font-display declarations.
* Keeps the runtime CSSOM fallback for dynamically inserted @font-face rules.
* Leaves the existing icon-font delay and cookie UI placement unchanged.

= 2.57.118 =
* Fixed reported WordPress Coding Standards findings for wp_rand(), SERVER_SOFTWARE sanitization, intentional external purge hooks, and the runtime JS scan global key.

= 2.57.117 =
* Kept the Cache Pages with Safe Tracking Cookies switch in Cache Engine.
* Moved the Safe Tracking Cookies and Never Cache When These Cookies Exist editors to Advanced Settings & Exclusions, immediately after Never Delay These Fonts / Patterns.

= 2.57.113 =
* Runtime: Release any remaining page-cache-build generation lock during shutdown so Set-Cookie/bypass or aborted output-buffer paths cannot leave fresh build-lock markers behind.
* Runtime: Stop refreshing another worker's runtime lock marker when acquisition fails; failed lock probes no longer make old page-cache-build locks look newly created.
* Diagnostics: Add Set-Cookie skip diagnostics that report the response cookie names responsible for STORE skip decisions without adding cookie-ignore behavior.

= 2.57.112 =
* Audit and tighten the 2.57.111 filesystem changes for WordPress-safe public-plugin behavior.
* Fix runtime lock release order so lock handles are unlocked/closed before WP-safe marker deletion is attempted.
* Route runtime lock marker deletion through a strict UltraCache locks/ path validator and the centralized wp_delete_file/WP_Filesystem-safe deletion helper.
* Make stale runtime-lock cleanup skip active locks with a flock probe, remove raw unlink fallback, and log delete failures for debugging.
* Keep CSS bundle manifest writes on schema v3 when adding/updating entries.

= 2.57.111 =
* Slim CSS bundle manifest entries so per-page CSS bundles no longer store heavy sourceDetails or repeated font diagnostic arrays in the runtime manifest.
* Delete runtime lock files on normal lock release and add guarded stale-lock maintenance for orphaned lock artifacts.
* Clean aged orphan manifest.json.tmp-* files during manifest writes and cap CSS manifest entries to prevent runaway manifest/tmp growth.
* Cap oversized dashboard action-job payload arrays so completed jobs do not keep huge CSS diagnostic payloads in options.

= 2.57.110 =
* Improve generic JS Runtime Scan / Extract Suggestions resolution for public-plugin use: script inventory now reads UltraCache-delayed script metadata (`data-ucwp-id`, `data-ucwp-src`, `data-ucwp-handle`) and inline `sourceURL` markers so delayed inline WordPress config blocks can still be mapped back to actual ids.
* Add generic dynamic `window[callbackName]()` analysis. When a minified script throws `window[o] is not a function`, UltraCache now scans related inline config/stack-frame sourceURL context for callback/function-name fields and surfaces resolved globals plus exact script ids/paths as review-only suggestions. No plugin/theme-specific mappings are used.

= 2.57.107 =
* Remove checkbox selection controls and the “Append selected review-only” buttons from JS Delay / Defer review-only suggestions. Review-only candidates remain visible and can be appended one by one after manual review.

= 2.57.106 =
* Allow manually reviewed JS Delay / Defer review-only candidates to be selected and appended from Runtime Scan and pasted Console Extract Suggestions. Review-only items remain opt-in and are never appended automatically.

= 2.57.105 =
* Runtime/Admin: Rebuild JS Delay / Defer Runtime Scan and Extract Suggestions on the clean 2.57.102 base, excluding the rejected 2.57.103 build.
* Runtime/Admin: Resolve dynamic `window[o] is not a function` and generic runtime function errors against the scanned page script inventory, suggesting exact loaded script paths/ids as review-only candidates instead of broad basenames.
* Runtime/Admin: Resolve missing globals through actual local same-site JS content and inline/external script ids found on the scanned page, including existing `*-js-before` / `*-js-after` companions only when present.
* Runtime/Admin: Remove the old Runtime Scan ElementorModules special-case suggestion block and TreeSixty/ThreeSixty special matching from JS dependency protection.

= 2.57.101 =
* UI: Move Delay These Fonts / Patterns and Never Delay These Fonts / Patterns above CSS Bundle Exclusions.
* UI: Replace fixed font list defaults with Scan Front Page buttons for detected font patterns.
* Runtime/Admin: Add a front-page font scanner that detects likely icon font families/files and non-icon text/brand font families from loaded stylesheets and inline CSS.
* Runtime/Admin: Auto-detect likely icon fonts now scans the front page when enabled and appends detected icon patterns to the visible Delay These Fonts / Patterns list.

= 2.57.100 =
* UI: Rename Manual LCP Hero / Slider selector to Manual LCP selector.
* UI: Merge the former Single LCP Image URL behavior into Manual LCP selector; the field now accepts CSS selectors, plain IDs, image URLs, and image URL fragments.
* UI: Render Delay These Fonts / Patterns and Never Delay These Fonts / Patterns in the same two-column sizing used by the other Advanced Settings & Exclusions textareas.
* Runtime: Split Manual LCP selector entries internally so image-like lines become manual LCP image overrides, while CSS/plain-ID lines continue to scope hero/slider LCP discovery.

= 2.57.99 =
* Tightened external cache card visibility so Varnish, OPcache, APCu, Nginx, and LiteSpeed cards/switches appear only when a real detected flush path exists.
* Fixed Varnish detection so the default endpoint alone no longer makes Varnish appear detected on servers without Varnish.
* Fixed OPcache/APCu detection so loaded-but-disabled or unavailable runtimes no longer show active flush cards.
* Kept LiteSpeed server-level purge support through the X-LiteSpeed-Purge response header when no LiteSpeed WordPress plugin API/hook exists.

= 2.57.95 =
* Moved dependency-safety recommendations into the existing visible JS Delay / Defer Exclusions Populate Defaults flow instead of applying hidden runtime exclusion lists.
* Expanded Populate Defaults with the previously internal JS dependency floor plus PrinceFrog/Davici 360-view and WooCommerce Google Analytics/GTag/GTM order-sensitive tokens.
* Defer/delay protection now honors the visible JS Delay / Defer Exclusions list; empty/custom-edited lists are no longer silently supplemented by built-in delay/defer exclusion fragments.

= 2.57.94 =
* Strengthen JS exclusion group protection for public-release debugging: user exclusions can now protect related defining scripts for function/global tokens such as TreeSixtyImageRotate, and legacy jQuery/jQuery Migrate clusters are kept ordered when dependency-sensitive exclusions are present.

= 2.57.93 =
* Strengthen JS Delay / Defer Exclusions enforcement so matched console-error exclusions protect the whole related script dependency group, including WordPress inline before/extra/after/translation blocks and nearby external dependencies.
* Improve exclusion matching for camelCase vs dashed script/plugin fragments, so values such as TreeSixtyImageRotate can match equivalent script URL fragments.
* No new switches or exclusion boxes; this is a JS optimizer safety fix for anonymous optimized HTML.

= 2.57.92 =
* Tighten Console Error Handler suggestions by filtering browser stack noise and keeping targeted script path fragments.
* Make the Console Error Handler use the same Runtime JS Scan suggestion engine used by live scans.
* Avoid broad pasted-console false positives such as same-origin domains, jquery.min.js, main.js, functions.js, generic functions, and broad WooCommerce fragments.
* Improve targeted suggestions for missing globals, jQuery plugin method errors, WooCommerce Coupon Box params, Complianz globals, WooCommerce Google Analytics inline-after errors, and targeted theme/plugin path fragments.

= 2.57.90 =
* Add a Console Error Handler helper under JS Delay / Defer Exclusions.
* Keep one shared JS Delay / Defer Exclusions field; no new exclusion box is added.
* Expand recommended JS Delay / Defer defaults with additional WordPress inline translation/config handles and common dependency-sensitive plugin fragments.

= 2.57.89 =
* Protect WordPress inline script dependency groups during Defer all JS so localized `*-js-extra`, `*-js-before`, and `*-js-after` snippets are not separated from their external script handle in anonymous optimized HTML.
* Strip native async/defer from dependency-sensitive script groups instead of forcing a defer that can make plugin globals unavailable.

= 2.57.87 =
* Add visible Delay all third-party JS switch using existing Third-Party Delay Exclusions and JS Delay / Defer Exclusions.
* Clarify the functional delay label as Delay known functional third-party JS.
* Delay all third-party matched scripts with data-ucwp-delay-reason="all-third-party" and keep known safe/functional reasons when pattern-matched.
* Open Top CSS bundle sources by bytes automatically after Run CSS Diagnostics.

= 2.57.86 =
* Improve manual LCP override selector support for simple CSS selectors such as `#hero > div.mask > img`, direct image selectors, and container selectors.
* Match Single LCP Image URL values against equivalent original/optimized image variants so manual AVIF/WebP URLs can still boost the matching rendered `<img>` tag.
* Force selected manual LCP images from lazy loading to eager/high priority and add explicit manual LCP reason markers.

= 2.57.84 =
* Strengthen LCP Image Priority fallback detection for first/main featured images in homepage, archive, and singular layouts.
* Keep manual, SR7, and hero detection ahead of the featured-image fallback.
* Add LCP reason/score markers for selected non-SR7 featured-image candidates.

= 2.57.82 =
* Remove Critical Request Chain Relief and LCP Boundary Defer from the Balanced profile while keeping both features available as manual options.
* Add a guarded first/main `.wp-post-image` fallback to LCP Image Priority for homepage, archive, and blog layouts without replacing existing manual hero, SR7, or slider detection.

= 2.57.81 =
* Extend Local Google Fonts Optimization to rewrite Google Fonts @import rules found in loaded same-origin CSS.
* Include loaded same-origin CSS files in manual Google Fonts rebuild scans so CSS-level @import URLs can be discovered without scanning entire plugin/theme directories.
* Localize Google Fonts @import rules hoisted by CSS bundle generation and report local/remote Google @import rewrite counts in Font Pipeline diagnostics.

= 2.57.80 =
* Synchronize successful Generate on Demand AVIF/WebP conversions into the persistent media conversion queue.
* Map on-demand source files back to attachment IDs and update best/avif/webp/both queue rows as skipped when fully optimized or pending when partially optimized.
* Keep on-demand queue synchronization best-effort and scoped to successful generation so frontend/warm-up rewrites are not blocked by queue mapping failures.

= 2.57.79 =
* Apply profiles in two passes so Object Cache setup cannot block the rest of a profile from being saved.
* Profile object-cache probes return clean unavailable results instead of browser-level HTTP 500 for expected backend failures.
* Improve dashboard REST failure diagnostics with action/method/route/status details.

= 2.57.78 =
* Load real read-only Advanced Diagnostics during the dashboard bootstrap even when Cache Statistics are OFF.
* Keep stats polling and counter collection hard-disabled when Cache Statistics are OFF.
* Revert dashboard settings saves to the existing POST /settings REST route and remove the short-lived /settings/save route introduced in 2.57.77.

= 2.57.75 =
* Changed fresh-install defaults so Lazy MailerLite nonce refresh and Delay icon font auto-detect start OFF, matching the All Off profile and preventing a new install from being shown as Custom before a profile is applied.

= 2.57.74 =
* Fix Object Cache backend selector to use a dedicated three-column layout.
* Keep each Redis/APCu/Disk description inside its own column under the button.
* Preserve the race-safe exclusive selector behavior.

= 2.57.72 =
* Polished the Object Cache backend selector into compact Redis/APCu/Disk buttons.
* Selected backend now uses the green primary action style; unselected backends use the standard dark action style.
* Moved backend explanations outside the selector buttons and removed the selected-state badge text.

= 2.57.71 =
* Replace Object Cache backend switches with an exclusive Redis/APCu/Disk selector so rapid clicks cannot leave the backend on a stale toggle state.
* Pass dashboard work-in-progress state into the Object Cache card so backend/settings actions are blocked while saves are queued.
* Rewrite Quick start & examples as a concise best-results guide, with Balanced profile onboarding, CSS/JS testing guidance, scheduled warm-up cap notes, and multiline media WP-CLI commands.

= 2.57.70 =
* Keep OPcache and APCu runtime status visible when Cache Statistics are disabled.
* Keep OPcache/APCu manual flush controls usable while cache counters, scans, polling, and dashboard stat collection remain hard-disabled.

= 2.57.69 =
* Fix Scheduled warm source-count refresh so freshly saved Full-site warm-up sources/menu/depth are used when generating the stored summary.
* Preserve already-normalized warm-scope arrays during summary generation to prevent selected sources from being dropped and shown as 0 discovered URLs.

= 2.57.66 =
* Fixed a dashboard crash in Scheduled warm limit helper text caused by reading the advanced settings form outside the React component scope.
* Clarified Cron Warm Up copy: cron warms HTML first, and when CSS Bundling is enabled missing bundles may be prepared before HTML is cached.

= 2.57.63 =
* Corrected the Scheduled warm limit helper text so detected menu/content/base URL totals add up correctly and the global crawl cap is labelled clearly.
* Performance profiles preserve user-maintained visible lists/textareas, including CSS bundle exclusions, JS delay/defer exclusions, CSS async exclusions, preload lists, cache exception lists, query allowlists, manual LCP selectors, and other editable safeguard lists.
* Performance profiles also preserve Redis connection infrastructure while still auto-detecting the active Object Cache backend/fallback from the configured infrastructure.
* Runtime Scan context control spacing was polished with a 10px gap between the label and dropdown.

= 2.57.60 =
* Runtime Scan now reports blocked/failed resources separately from JavaScript dependency errors.
* Resource load failures such as extension-blocked tracking/ads scripts are shown as “Blocked / failed resources” and are not counted as missing JS Delay / Defer exclusions.
* The displayed scan URL now removes ucwp_runtime_js_scan_context along with the other internal scan query args.
* Polished the Runtime Scan context dropdown to use the normal dashboard select styling and spacing.

= 2.57.55 =
* AVIF/WebP Batch UI polish: action buttons now display in a single column, helper descriptions are plain text, and media conversion progress now appears in the shared job-progress position below the Warm Cache / AVIF-WebP row.

= 2.57.54 =
* Dashboard layout polish: Profiles now sit directly below Stats, Custom is full-width, presets use a two-column grid, and the main flow puts Warm Cache and AVIF/WebP Batch before Cache Engine and Media Optimization.

= 2.57.53 =
* Fixed performance profile object-cache auto-detection so Redis, APCu, and Disk probes run in deterministic FIFO order.
* Profile object-cache detection now skips the runtime payload probe and uses backend availability only, preventing accidental Disk fallback from pending/indeterminate probe results.
* All Off no longer runs object-cache or query allowlist detection; it simply disables the profile-controlled features.

= 2.57.52 =
* Dashboard action lifecycle cleanup: switches, buttons, manual tests, and queued dashboard actions now enter a single FIFO UI operation pipeline.
* Persistent per-operation processing toasts remain visible until each queued operation completes.
* Dashboard stats/diagnostics refresh is deferred until the operation queue is empty, preventing out-of-order refresh races.

= 2.57.49 =
* Polished object-cache dashboard passive status so manual test/probe rows are not shown as “Not tested yet” before a user test.
* Kept Redis payload guard details out of passive status.
* Rendered dashboard status values without background/pill styling.

= 2.57.47 =
* Final PHPCS public-readiness polish after the 2.57 hardening cycle.
* Replaced the remaining production-scan `parse_url()` fallback with `wp_parse_url()`.
* Rendered managed runtime config without `var_export()` so production scans no longer flag debug/development output helpers.

= 2.57.45 =
* Polished Varnish admin-mode UI copy placement and styling.
* Moved helper copy to the bottom of the Varnish card and removed warning-style border/background from the admin-mode security helper text.

= 2.57.44 =
* Removed the development-only Varnish debug log feature from settings, dashboard UI, schema, and runtime code.
* Redacted Varnish result detail strings before storing/displaying purge/test results.
* Escaped Varnish BAN URL expressions so URL purges match literal paths/queries instead of raw regex fragments.

= 2.57.43 =
* Added passive/manual-test-only clarity flags to default dashboard object-cache diagnostics.
* Kept object-cache backend truth visible without reintroducing automatic live Redis/payload probes.

= 2.57.42 =
* Improved object-cache backend truth and UI clarity.
* Keeps selected/active/fallback backend status visible even when cache stats are disabled.
* Treats runtime-only object cache as an active fallback state.

= 2.57.41 =
* Hardened advanced-cache stale CSS handling so cached HTML referencing missing generated CSS/font/optimized CSS assets is invalidated instead of served.
* Added generated CSS bundle and delayed-font companion verification after atomic writes.

= 2.57.40 =
* Refined Runtime JS Scan reporting by redacting internal diagnostic URL parameters before storing/displaying report URLs.
* Kept review/manual Runtime JS Scan suggestions non-appendable and surfaced raw captured browser errors.

= 2.57.39 =
* Hardened cache-poisoning bypass parity between the WordPress engine and advanced-cache drop-in.
* Added non-removable security query/path guards and invalid internal-control request bypass handling.

= 2.57.38 =
* Hardened object-cache drop-in recursive cleanup and uninstall directory cleanup against symlink traversal.
* Narrowed runtime secret filesystem allowlist to the canonical off-docroot secret path and its atomic temp sibling only.

= 2.57.37 =
* Restricted automatic drop-in/runtime-config full reconcile writes to WP-CLI or privileged plugin-management admins.
* Hardened frontend loopback URL validation and redacted internal runtime/profile query tokens from debug contexts.

= 2.57.36 =
* Converted managed runtime config to guarded runtime-config.php.
* Hardened off-docroot runtime secrets, revalidate secret storage, and secret file permissions.

= 2.57.35 =
* Completed hard removal of the experimental JS bundling UI/runtime leftovers after JS bundling removal.

= 2.57.34 =
* Removed the experimental safe deferred JS bundling feature while keeping JS defer, delay, runtime scan, LCP boundary defer, and main-thread relief behavior unchanged.

= 2.57.33 =
* Hard-stopped object-cache symlink traversal and kept managed media temp files under the UltraCache managed cache directory.

Release notes are maintained in `changelog.txt`.

== Upgrade Notice ==

= 2.57.70 =
OPcache/APCu runtime boxes and manual flush buttons remain available when Cache Statistics are disabled.

= 2.57.69 =
Scheduled warm limit source counts are generated from the freshly saved Full-site warm-up settings.

= 2.57.66 =
Fixes a Scheduled warm limit dashboard crash and clarifies that cron warm-up may prepare missing CSS bundles when CSS Bundling is enabled.

= 2.57.63 =
Scheduled warm limit helper text now reports detected URL totals accurately and labels the global crawl cap clearly. Performance profiles continue to preserve user-maintained visible exclusion/safeguard lists and Redis connection infrastructure.

= 2.57.60 =
Runtime Scan now shows extension/client-blocked resources separately from JavaScript dependency errors and cleans the displayed anonymous scan URL.

= 2.57.55 =
AVIF/WebP Batch layout polish only. Media batch controls now match the Warm Cache style and media conversion progress appears in the shared job-progress area.

= 2.57.54 =
Dashboard layout polish only. Profiles now appear immediately below Stats, followed by Warm Cache, AVIF/WebP Batch, Cache Engine, and Media Optimization.

= 2.57.53 =
Performance profile object-cache detection now waits for deterministic Redis > APCu > Disk backend probes before saving profile settings, preventing fast-click races and accidental Disk fallback.

= 2.57.52 =
Fixes the Lazy MailerLite nonce refresh switch persistence so All Off and manual toggles save correctly.

= 2.57.52 =
Dashboard action lifecycle cleanup: queued switches, buttons, manual tests, and warm/profile actions run in strict FIFO order with persistent processing toasts and final dashboard refresh after the queue drains.

= 2.57.49 =
Object-cache dashboard polish after backend truth hardening. Update, purge cache, warm important pages, and review diagnostics.

= 2.57.47 =
Final PHPCS polish for the public package. Update, purge cache, warm important pages, and review diagnostics.

= 2.57.45 =
Varnish UI copy polish after Varnish debug-log removal and endpoint/secret hardening.

= 2.57.44 =
Removes development-only Varnish debug logging, redacts Varnish result details, and safely escapes BAN expressions.

= 2.57.41 =
Hardened generated CSS stale-reference handling so missing CSS bundle/font references are invalidated before serving cached HTML.

= 2.57.39 =
Adds stricter cache-poisoning bypass parity between the WordPress engine and advanced-cache drop-in.

= 2.57.36 =
Moves runtime config to guarded PHP and hardens off-docroot runtime secrets.
