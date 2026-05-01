# UltraCache

UltraCache is a production-oriented WordPress performance plugin focused on page-cache delivery, cache warming, Redis/APCu object caching, Varnish-aware purge workflows, media optimization, CSS bundle generation, and operator-friendly diagnostics.

## Varnish Support and cache-backend compatibility

| Feature / backend | Status | Recommended use | Notes |
| --- | --- | --- | --- |
| **Varnish Support** | Optional integration | Use when the site is behind Varnish and you want UltraCache to test, purge, or ban cached pages. | HTTP mode targets a local Varnish listener, for example `127.0.0.1:82`, and blocks public frontend endpoints such as `domain.com:443`. Admin mode should use localhost/private endpoints only, for example `127.0.0.1:6082`. Do not expose the Varnish admin port publicly. |
| **Redis Object Cache** | Recommended production backend | Best persistent object-cache backend when the PHP Redis extension and a stable Redis service are available. | UltraCache stores Redis secrets in protected runtime sidecar config rather than exposing them in generated drop-ins. |
| **APCu Object Cache** | Safe local fallback | Good for single-server sites when Redis is unavailable. | APCu is local to the PHP runtime and is cleared on PHP-FPM restart. If APCu writes fail or memory is full, UltraCache falls back safely to runtime-only behavior. |
| **Disk Object Cache** | Advanced/debug only | Use only when explicitly testing object-cache behavior. | Not recommended for production because it can create many small files and increase filesystem I/O. It is not used automatically as a fallback. |

## Current build

- Version: `2.56.141`
- Build type: Media queue UX/state safety pass
- Runtime focus: resumable media conversion queue, already-optimized reporting, and optimized-storage repair safety.
- Default behavior: diagnostics are read-only; cleanup keeps the existing grace period and per-run delete limit.

## Recommended setup

### Safe baseline

1. Enable **Page Cache**.
2. Save settings.
3. Purge all cache.
4. Warm the homepage/menu cache.
5. Visit the homepage twice and confirm the second request can return `X-Ultra-Cache: HIT`.

### Object cache

Recommended order:

1. **Redis** when available and connected.
2. **APCu** as a local single-server fallback.
3. **Runtime-only** when neither Redis nor APCu is available.
4. **Disk** only when manually selected for advanced/debug testing.

### Analytics hit backend

UltraCache records page-cache hit analytics using the lowest-risk available backend:

1. APCu
2. Redis
3. Disabled when neither APCu nor Redis is available

It does not rely on per-hit disk logging when APCu/Redis are unavailable.

### Media Optimization

The Media Optimization card now uses one master switch:

- **Media Optimization** = master on/off switch
- **Output Policy** = `Auto`, `AVIF`, or `WebP`

AVIF/WebP availability depends on the PHP image stack, especially Imagick/GD codec support.

### CSS Bundle Summary

CSS bundle status is shown as **CSS Bundle Summary** in Warm Cache and Activity Summary. It reports:

- bundles built
- styles bundled/scanned
- skipped/unresolved stylesheets
- last CSS bundle warm time
- last warm message

### Frontend HTML rewrite safety

UltraCache uses a layered approach for frontend HTML optimizations:

- `WP_HTML_Tag_Processor` is preferred for tag/attribute rewrites when available.
- Legacy regex/string fallbacks remain for older WordPress versions or malformed edge cases.
- Safety wrappers keep the original HTML when a rewrite returns suspicious output.
- CSS text rewrites remain string-based where the WordPress HTML tag parser is not the right tool.

## Manual JS exclusions

Do not hard-code theme-specific dependencies into the plugin. If a theme or custom script has a dependency-order issue, add it to **JS Delay / Defer Exclusions** manually.

Good exclusion candidates are usually script handles, filenames, or globals that appear in browser console errors, for example:

```text
some-theme-runtime.js
custom-slider.js
SomeThemeGlobal
```

Core shared systems such as WordPress, WooCommerce, Elementor, common slider runtimes, and critical dependency libraries are handled by generic built-in safeguards.

## Recommended post-update test

After installing a new UltraCache build:

1. Save settings once.
2. Purge all cache.
3. Warm the homepage/menu cache.
4. Open the homepage twice and confirm cache headers.
5. Check browser Console for JavaScript errors.
6. Check Network for local `/wp-content/` or `/wp-includes/` assets returning `404`, `403`, or `500`.
7. Check homepage, product page, cart, checkout, search, menu, sliders, fonts, and mobile layout.
8. Review Diagnostics for page cache, object cache backend, analytics backend, OPcache/APCu cards, CSS Bundle Summary, and generated drop-in versions.

## Useful SSH checks

Replace the URL/path as needed:

```bash
cd /path/to/wordpress
wp ultracache status
wp ultracache stats --format=json
wp ultracache purge --all
curl -I https://example.com/
curl -I https://example.com/
```

The second public anonymous request should be eligible for a HIT when the page is cacheable.

## Main feature areas

### Full-page cache

UltraCache stores cacheable public pages as static files under:

```text
wp-content/cache/ultracache/
```

The plugin manages a custom `advanced-cache.php` drop-in that can serve those files early in the WordPress bootstrap.

### Warmup and cleanup

UltraCache supports:

- homepage warmup
- menu URL warmup
- full-site warmup
- HTML cache warmup
- CSS bundle warmup
- scheduled cleanup
- optional warm after scheduled cleanup/manual purge

### Object cache

UltraCache can manage an `object-cache.php` drop-in using Redis, APCu, runtime-only fallback, or explicit disk mode.

### Media optimization

UltraCache can generate AVIF/WebP image variants when the server image stack supports them.

### Frontend optimization

Optional frontend optimization areas include:

- JavaScript defer/delay safeguards
- async CSS delivery
- CSS bundle generation
- LCP image priority hints
- Google Fonts optimization
- CLS image-dimension helpers
- speculation rules
- asset-chain cleanup

## Troubleshooting quick guide

### Cache never HITs

Check:

- Page Cache is enabled.
- `WP_CACHE` is present and true in `wp-config.php`.
- `wp-content/advanced-cache.php` exists.
- The request is anonymous and public.
- No cart/session/login cookies are present.
- The URL is not excluded.
- Query-string handling is configured correctly.

### Object cache not active

Check:

- Selected backend vs active backend in Diagnostics.
- Redis extension and connection if using Redis.
- APCu extension if using APCu.
- Generated `object-cache.php` version/storage format.

### Frontend script errors

Check browser Console and add only site-specific theme/custom scripts to manual **JS Delay / Defer Exclusions**. Avoid hard-coded theme protections in the plugin.

### CSS/layout issue

Check:

- CSS Bundle Summary.
- Skipped/unresolved stylesheet count.
- Browser Network for missing CSS files.
- Whether the issue disappears when CSS bundling/async CSS is disabled.

## Changelog

### 2.56.141
- Release candidate cleanup: bumped metadata/readme version and removed stray control characters from Varnish/server cache detection regular expressions. No runtime feature behavior changes.

### 2.56.140
- Public release audit fixes: moved conversion support details into the AVIF/WebP Batch Conversion box, hardened media queue REST args, removed REST media format aliasing, added local URL guard to Inspect URL, and added destructive filesystem allowed-root guards.

### 2.56.139
- UI polish: keep Warm Cache action buttons in a single vertical column.
- UI polish: add spacing between media conversion operation counters.

### 2.56.138
- Moved AVIF/WebP batch conversion into its own dashboard box below Media Optimization.
- Added visible buttons for Start/Resume Conversion, Rebuild Media Queue, Verify/Repair Queue, Retry Failed, and Clear Completed Queue Rows.
- Moved media conversion live progress/logs into the Batch Conversion box only; cache warm-up UI is unchanged.
- Added media-only operation copy and counters for attachments checked, image units checked, AVIF generated, WebP generated, already optimized, and failed.
- Added REST endpoints for media queue status, rebuild, process, repair, retry failed, and clear completed.

### 2.56.137
- Media queue UX/state safety: complete queues now report already optimized instead of running unnecessary batches.
- Added optimized-storage missing detection and repair requeue path when AVIF/WebP output folders disappear.
- Limited media queue rebuilds preserve existing completed queue state.
- Hotfix: cleaned the media conversion WP-CLI contract so `--format` controls output only and `--media-format` controls image targets.
- Fixed media conversion dashboard progress so attachment progress cannot exceed the queue total while image units are tracked separately.
- Improved media queue building and pause display copy so queue-building state is explicit.

### 2.56.135
- Added a persistent media conversion queue table for resumable AVIF/WebP processing.
- Media conversion now processes pending queue items instead of re-scanning the full library for every run.
- Added queue status, rebuild, process, retry-failed, and clear-completed support to the WP-CLI media command.
- Admin media diagnostics now show conversion queue pending/done/failed/skipped counts.

### 2.56.134
- Polished cache storage diagnostics so CSS bundle file counts use recognized bundle files instead of double-counting delayed-font CSS files.
- Show capped storage scans as minimum values in the dashboard, including AVIF/WebP counts such as 8,000+.
- Clarified WP-CLI cleanup reporting with recognized CSS bundle before/after counts, old orphan-like files eligible for cleanup, and recent orphan-like files protected by grace.

### 2.56.132
- Fixed a WP-CLI scheduled cleanup fatal by declaring the static warm-suppression flag used while cleanup purges cache.

### 2.56.131
- Fixed **Consolidate Remaining CSS** so the leftover CSS bundle pass follows the independent `leftoverCssBundleEnabled` setting in `safe`, `aggressive`, and `full` CSS bundle modes. The selected main CSS bundle mode no longer silently disables leftover consolidation, and leftover-generated files now use a semantic `bundle-leftover-*` filename prefix.

### 2.56.130
- UI polish pass: move Font Pipeline Diagnostics into Advanced Diagnostics, simplify CSS Bundle Summary, remove duplicate Speed Diagnostics CSS source list, add feature request support link, and compact Media Batch support info.

### 2.56.129
- Security/cache correctness audit pass: secret redaction, hard sensitive-query cache bypass floor, and diagnostics.

### 2.56.128
- Varnish/Reverse Proxy UI clarity build. Clarifies HTTP endpoint vs admin-secret modes, improves status diagnostics, dynamic effective purge method labels, and secret-safe admin status display.

### 2.56.127g
- Makes CLS image-dimension optimization use the faster img-only regex path by default to reduce STORE rewrite cost.
- Adds per-request CLS image dimension resolution caching for repeated image URLs.
- Keeps the precise WP_HTML_Tag_Processor path available behind the `ucwp_cls_dimensions_use_html_tag_processor` filter.

### 2.56.127e
- Adds Frontend Rewrite Stage Breakdown to Speed Diagnostics so slow HTML rewrite stages are visible.
- Fixes CSS duplicate diagnostics so single non-blocking delayed-font links no longer count as duplicates.
- Clarifies that profiler sub-stage timings are diagnostic and may not add up exactly due nested wrappers.

### 2.56.127d
- Deepened the Speed Diagnostics UltraCache overhead probe for template_redirect and buffering setup timings.
- Ignored noscript stylesheet fallbacks in critical-path duplicate/mixed CSS diagnostics to avoid false blocking reports.
- Add Speed Diagnostics UltraCache overhead probe for maybe_start_buffering sub-steps.
- Add duplicate/mixed-status CSS link diagnostics for delayed-font and bundle links.

### 2.56.127b
- Renames the admin Performance Profiler UI to Speed Diagnostics with clearer user-facing button labels.
- Saves the timing breakdown immediately for profiler-triggered STORE requests while keeping normal visitor store bookkeeping deferred.
- Replaces the technical missing STORE profile message with a clearer timing-breakdown diagnostic message.

### 2.56.127
- Polishes object-cache visibility without changing the object-cache engine/drop-in behavior.
- Reports the real fallback backend dynamically in stats instead of assuming APCu.
- Adds fallback message metadata to object-cache stats for clearer Redis → APCu/runtime reporting.
- Makes APCu warnings less alarming when APCu is used only for analytics/shared-memory and not as the active object-cache backend.

### 2.56.122
- Makes CSS bundles proxy-stale-safe by preserving `css-bundles/` during purge and using cleanup grace for old bundles.
- Treats main CSS bundles and delayed-font companion CSS files as a lifecycle pair.
- Adds cached HTML validation for missing css-bundle references and resets cron warm-up queue after cache flush.

### 2.56.121
- Regression fix: restores the dependency-aware ordered delayed-loader path for same-host scripts instead of forcing native `defer` for every local asset.
- Prevents grouped inline-before / inline-after configs from running out of order for integrations such as Complianz, Google Site Kit, WooCommerce and similar scripts.
- Keeps **JS Delay / Defer Exclusions** and hard dependency blockers as the final priority.
- Normalizes delayed script URLs to absolute public URLs while preserving the original source for diagnostics.

### 2.56.116
- Tightens **Defer all JS** into a truly aggressive mode by removing legacy conservative JS Delay / Defer exclusions when the switch is enabled.
- Keeps only the absolute dependency floor out of **Defer all JS**, such as jQuery and core WP globals/dependencies.
- Stops generic inline data/localization from automatically blocking **Defer all JS** so WooCommerce/theme scripts can be deferred when the aggressive switch is explicitly enabled.

### 2.56.115
- Adds a new **Defer all JS** toggle under **Frontend JS & Request Chains** for aggressive native defer on eligible scripts.
- Keeps only an absolute dependency floor out of **Defer all JS**, including jQuery, inline-coupled scripts, and core WP globals that commonly break when moved.
- Renames the shared visible exclusions panel to **JS Delay / Defer Exclusions** and applies it across defer, delay, LCP boundary defer, and related JS optimizations.

### 2.56.114
- Adds a visible **Lazy MailerLite nonce refresh** toggle in **Frontend JS & Request Chains**.
- Prevents MailerLite `ml_create_nonce` admin-ajax calls from running on page load by returning a local temporary success response.
- Refreshes the real MailerLite nonce on first form interaction or immediately before submit, then continues the normal MailerLite submit flow.
### 2.56.112
- Allows **Advanced Runtime Font CSS Rewrite** to run during slider/hero safe mode while still respecting broad **Frontend Safe Mode**.
- Preserves current-request original-to-optimized font CSS mappings so the runtime MutationObserver can be injected even after server-side font links have already been rewritten.
- Adds `data-ucwp-font-css-map-source` diagnostics to show whether the runtime map came from cache, current request, HTML links, or bundle manifest.

### 2.56.110
- Adds **Delay icon font-face blocks**, an opt-in CSS bundle feature that moves matched icon-font `@font-face` blocks into a delayed non-render-blocking font stylesheet.
- Adds visible/editable **Delay These Fonts / Patterns** and **Never Delay These Fonts / Patterns** fields, plus a broad icon-font auto detector.
- Adds delayed font diagnostics to **CSS Bundle Summary** and last CSS bundle warm reporting.

### 2.56.109
- Adds **Full CSS Bundle** as a normal **CSS Bundle Mode** alongside Safe and Aggressive. Full mode consolidates all eligible local stylesheet links into the generated bundle while preserving non-all media rules with `@media` wrappers.
- Replaces the two CSS bundle mode toggles with a single **CSS Bundle Mode** selector: Safe, Aggressive, and Full CSS Bundle.
- Reworked the SR7 LCP runtime helper to use the warmed/static LCP preload URL first, removing interval-based DOM area scans and repeated `getBoundingClientRect()` measurements that could appear as forced reflow in Lighthouse/PageSpeed.
- Keeps the 2.56.106 SR7 module-background preload mapping fix intact, including generated `/revslider/o/` to real media-library source resolution.

### 2.56.105
- Adds debug attributes to LCP preload links so diagnostics can distinguish the plugin preload winner from the browser actual LCP request.
- Tightens the SR7 runtime helper so the scoped winner is re-evaluated repeatedly while SR7 paints module backgrounds and slider layers.
- Prefers scoped visible SR7 module backgrounds over decorative/generated RevSlider image-list assets when marking runtime LCP candidates.
- Keeps CSS bundle, warmup, purge, and defer/delay behavior unchanged.

### 2.56.100
- Refines SR7/Revolution Slider LCP detection so static/shared slide backgrounds are preferred over decorative rotating slide layers.
- Adds static-slide aware SR7 LCP diagnostics via `data-ucwp-sr7-role`, `data-ucwp-lcp-reason`, and `data-ucwp-lcp-score` markers.
- Uses the SR7 module/container visual boundary for static-slide LCP candidates instead of the DOM-last static slide node.
- Keeps CSS bundle, safe/functional third-party delay, and runtime dependency guards unchanged.

### 2.56.98
- Uses `delaySafeThirdPartyJsEnabled` as the clean safe-third-party delay switch; the old generic third-party switch is no longer used.
- Adds delayed inline companion handling so matching WordPress before/extra/after inline script blocks execute with their delayed external script in DOM order.
- Updates the delayed script loader to execute delayed inline script markers as well as delayed external scripts.
- Keeps the 2.56.97 safe/functional pattern engine and LCP Boundary Defer semantics intact while fixing functional-delay runtime dependency ordering.

### 2.56.97
- Renames the third-party delay UI to **Delay safe third-party JS** and adds **Delay functional third-party JS** for visible widgets such as consent/cookie scripts, captcha, maps, chat, booking, embedded forms, and reviews.
- Adds visible/editable safe third-party patterns, functional third-party patterns, and third-party delay exclusions with Populate Defaults support.
- Replaces the targeted gtag-only delay pass with a general pattern-based third-party delay engine and diagnostic delay reasons.
- Corrects **LCP Boundary Defer** so it uses the detected LCP image position as the HTML boundary and delays eligible local scripts printed after that image.

### 2.56.96
- Adds a targeted HTML delay pass for external Google gtag.js loader scripts when Delay Third-party JS is enabled.
- Preserves visible user JS Delay / Defer Exclusions while bypassing generic inline-segment blocking only for queue-safe analytics loaders.
- Marks delayed analytics loader tags with `data-ucwp-delay-reason` for clearer diagnostics.

### 2.56.95
- Changes safe page CSS bundle application from duplicate injection to manifest-based conservative replacement, removing only bundled source stylesheet links while leaving unmatched/excluded/runtime links intact.
- Gives safe CSS bundles real request-reduction behavior without switching to the broader aggressive eligibility rules.

### 2.56.94
- Treats frontpage HTML + CSS warm as verified when the loopback HTTP client times out but post-warm cache inspection confirms cached HTML exists and contains the CSS bundle.
- Keeps the timeout visible in the command message while avoiding a false error exit for verified cache writes.
- Adds a `warmVerifiedAfterTimeout` result flag for diagnostics and REST consumers.

### 2.56.93
- Allows safe CSS bundle injection to run on Slider Safe Mode pages instead of building orphaned bundles that never appear in cached HTML.
- Adds final warm verification to frontpage CSS bundle builds, including bundle bytes, cached HTML bundle refs, and stylesheet link counts.
- Reports a warning/error-style result when a CSS bundle is built but the final cached HTML warm fails or does not contain the bundle reference.

### 2.56.92
- Adds Async Remaining CSS decision diagnostics to STORE/CSS diagnostics so applied, skipped, and unresolved stylesheet decisions show explicit reasons.
- Runs Async CSS after font CSS optimization so excluded local stylesheets can still receive font-display rewrites before async eligibility is evaluated.
- Keeps CSS Bundle Exclusions outside generated CSS bundles while still allowing eligible excluded local stylesheets to pass through self-hosted font CSS optimization.
- Allows Async Remaining CSS and Aggressive Async CSS to run even when slider-safe mode is enabled; CSS Bundle Exclusions no longer suppress those passes.
- Fixes font-display injection so minified @font-face blocks receive a valid semicolon before `font-display: swap`.

### 2.56.89
- Changes CSS diagnostics source actions from copy-only to Append exclusion line inside the visible CSS Bundle Exclusions editor.
- Deduplicates appended CSS exclusion lines against the current textarea draft and marks covered suggestions as Already in exclusions.
- Clarifies that CSS Bundle Exclusions keep matching stylesheets outside generated bundles and loaded normally as original stylesheet links.
- Keeps CSS exclusion actions manual: diagnostics do not automatically save or apply exclusions until the user saves the editor.
- Preserves the 2.56.88 Advanced Settings layout with CSS Bundle Exclusions and JS Delay / Defer Exclusions at the bottom of Advanced Settings & Exclusions.

### 2.56.84
- Polishes JS Delay Scan results into live-count sections: Missing recommended, Already listed recommended, and Review-only detected.
- Recomputes missing/already-listed counts from the current JS Delay / Defer Exclusions textarea so the header and append button cannot show stale missing values after populate/append/manual edits.
- Renames the append action to Append Missing Recommended and only enables it when appendable scan results are missing from the visible textarea.
- Shows Missing, Already listed, Recommended, Detected, and Review-only counters so users can see exactly where missing exclusions are.


### 2.56.80
- Adds a full-width JS Delay / Defer Exclusions panel with Populate Defaults, Scan Latest Profile, Append New Suggestions, and Save controls.
- Adds JS Delay Safety Scan diagnostics to STORE profiles for inline handler/global dependency breaks caused by delayed scripts, with high-confidence suggested visible exclusions and duplicate-safe appending.

### 2.56.77
- Refines Fix sliders / hero sections asset protection so generic words like `slider`, `carousel`, `slideshow`, and `hero` are no longer used for URL/handle protection. This avoids false-positive protection for non-hero assets such as product-filter range sliders while keeping broad generic terms for markup detection only.

### 2.56.76
- Adds Critical Request Chain diagnostics to the Performance Profiler, showing render-blocking CSS/JS candidates, delayed/protected script status, protection reasons, origins, locations, and suggested next actions without changing runtime loading behavior.

### 2.56.75
- Adds visible suggested CSS Bundle Exclusion lines for top CSS bundle sources in the Performance Profiler source list.
- Adds copy-to-clipboard controls for suggested exclusion lines so operators can move heavy sources to the editable CSS Bundle Exclusions field after visual testing. Runtime CSS loading behavior is unchanged.

### 2.56.74
- Adds CSS bundle critical-path diagnostics to the Performance Profiler summary, including bundle bytes, source stylesheet bytes, largest source, top CSS source contributors, and render-blocking stylesheet counts.
- Adds large CSS bundle warnings when the generated bundle crosses diagnostic thresholds. Runtime CSS loading behavior is unchanged.

### 2.56.73
- Replaces site-specific placeholder examples in Advanced Settings textareas with generic plugin-safe examples.
- Updates Manual LCP Hero / Slider selector copy so placeholders do not reference the development/test site. Runtime behavior is unchanged.

### 2.56.72
- Maps selected local LCP preload candidates to existing one-to-one UltraCache AVIF/WebP equivalents when media optimization is enabled, while keeping same-origin image preloads free of `crossorigin`.

### 2.56.71
- Adds a generic LCP candidate scoring engine inside the visible Manual LCP Hero / Slider selector scope, covering img/srcset, SR7 attributes, inline `background-image`, and shorthand `background: url(...)` sources.
- Prefers rendered high-confidence hero/background URLs, including actual AVIF/WebP URLs present in the HTML, instead of relying on the first marked SR7 image only.
- Removes `crossorigin` from same-origin image preloads and adds image MIME `type` hints so CSS background preloads can be reused by the browser without credentials-mode mismatch warnings.

### 2.56.70
- Adds a visible Manual LCP Hero / Slider selector field for high-confidence hero/slider targeting, accepting generic entries like `#main-hero`, `homepage-slider`, or `.hero-slider`.
- Uses a found manual hero/slider selector as the preferred SR7 LCP preload scope so the first marked candidate in that block drives the preload target.
- Keeps SR7 runtime LCP priority, slider-aware Boundary Defer, protected slider script exclusions, and HTTPS-safe local Google Fonts URLs unchanged.

### 2.56.68
- Normalizes UltraCache local Google Fonts URLs inside generated CSS to root-relative `/wp-content/cache/ultracache/google-fonts/...` paths so HTTPS pages do not request blocked `http://` font assets.
- Rewrites existing local Google Fonts CSS cache files when reused, so old cached `http://` font URLs are corrected without deleting the downloaded font binaries.

### 2.56.67
- Keeps SR7/Revolution/Swiper/Slick runtime assets protected while boundary defer remains slider-aware and conservative.
- Updates admin copy to explain that Fix sliders / hero sections is a protection layer, not a replacement for LCP Image Priority.

### 2.56.66
- Adds an admin beforeunload guard while queued settings, save requests, dashboard actions, or long admin processes are still running.
- Keeps queued/running dashboard action toasts persistent until success or error replaces the same notice.
- Improves critical cache setting save UX so cache-impacting toggles stay visibly queued/saving and dashboard actions wait for the save to finish.

### 2.56.65
- Removes stray Google Fonts preconnect/dns-prefetch hints after CSS Aggressive Bundling folds the original Google Fonts stylesheet into the generated external bundle.
- Keeps the 2.56.64 aggressive fallback cleanup intact: no per-original noscript fallback links are restored.

### 2.56.64
- Stops CSS Aggressive Bundling from adding per-original noscript fallback links after replacing matched local stylesheets with the generated bundle.
- Adds Performance Profiler CSS output diagnostics for final HTML size, inline CSS bytes, fallback link/marker counts, and noscript count.
- Adds a visible profiler warning when Inline CSS Bundling creates large cached HTML, without silently overriding the user's inline setting.

### 2.56.63
- Corrects the saved Performance Profiler summary mode so callback profiler results show as `callback` in the last-profile endpoint and UI instead of looking like compact-only profiles.
- Improves the profiler help text to explain when the tool is useful and what question each run type answers.
- Shows up to 12 plugin/theme/core timing groups and improves mobile wrapping for profiler action buttons.

### 2.56.62
- Converts the wp-admin Performance Profiler into a collapsed accordion to keep the dashboard cleaner.
- Improves the profiler helper copy so it explains when the tool is useful instead of describing internal mechanics.
- Adds a Plugin / Theme Time Summary that aggregates callback profiler time by plugin, theme, and WordPress core.
- Adds dedicated spacing for the profiler action buttons so the controls no longer appear stuck together.

### 2.56.61
- Adds a manual Performance Profiler card to wp-admin for Compact STORE, Verbose STORE, and Callback Profiler runs.
- Keeps profiler activation request-scoped and explicit; no persistent frontend profiler setting is introduced.
- Adds summary display plus Download JSON and Clear Last Profile actions.

### 2.56.60
- Makes the default STORE profile compact by recording only key lifecycle/rewrite checkpoints, while `X-UltraCache-Store-Profile-Verbose: 1` or `?ucwp_store_profile_verbose=1` keeps the full diagnostic checkpoint stream.
- Removes automatic `hook_summary` expansion from normal STORE profiles; hook callback summaries are now verbose-only.
- Keeps manual callback profiling separate and explicit via the existing callback profiler trigger, without leaking into normal STORE/HIT requests.

### 2.56.59
- Cleans up the temporary wide init/debug profiler checkpoints from the STORE profiler while keeping the manual callback profiler available only when explicitly requested.
- Limits the diagnostic `X-Ultra-Cache-Source` header to explicit debug requests via `X-UltraCache-Debug: 1`, reducing normal production header noise.
- Keeps the 2.56.58 runtime-config resync after full purge so advanced-cache HIT delivery remains active immediately after purging.

### 2.56.58
- Rebuilds the runtime-config sidecar immediately after full purge so the next anonymous HIT can be served by the advanced-cache drop-in instead of falling back to the later WordPress engine early-hit path.
- Adds an explicit `X-Ultra-Cache-Source` response header for advanced-cache vs WP-engine HIT diagnostics.

### 2.56.57
- Makes deep callback profiling fully manual/opt-in via `X-UltraCache-Callback-Profile: 1` or `?ucwp_callback_profile=1`, and only when STORE profiling is also enabled.
- Avoids registering diagnostic lifecycle/callback wrappers on normal frontend requests, protecting regular STORE/HIT performance.
- Treats profiler query args as diagnostic-only so they do not force query-arg cache bypass in WordPress cacheability checks.

### 2.56.56
- Moves object-cache support checks in frontend settings sanitization to cached/read-only mode; live support checks remain in admin, REST, AJAX, WP-CLI, activation, and settings-save flows.
- Enriches profiler slow-callback checkpoints with callback, plugin/origin, file, hook, and priority fields; init target callback timings now feed the same callback timing summary.

- 2.56.55: Adds guarded callback timing profiler for `wp_enqueue_scripts`, `template_redirect`, loader filters, srcset, and shutdown; repetitive settings checkpoints are verbose-only.
- 2.56.54: Skips live frontend compression loopback probes during normal settings/default sanitization; cached/browser diagnostics remain available.
- 2.56.53: Adds an opt-in early buffer/cacheability micro-profiler around maybe_start_buffering(), should_bypass_cache(), settings loading, and support-probe sanitization to isolate the remaining pre-early-HIT STORE delay.
- 2.56.40: Hardens the 2.56.39 Google Fonts admin-scan workflow by making legacy live-build queues/events cleanup-only, keeping frontend missing-font handling read-only, and preserving the page-cache stampede lock behavior.
- 2.56.39: Adds page-cache stampede protection for cold concurrent requests, keeps Google Fonts rebuilding manual/admin-controlled without frontend live builds, and avoids leaving server-cron-only Google Fonts rebuild events after settings save.
- 2.56.38: Moves Local Google Fonts Optimization to a controlled admin/save/manual-rebuild scan pipeline, stops frontend Google Fonts build/queue behavior, adds Additional URLs for scanning, and preserves the Google Fonts cache during Flush All Cache.
- 2.56.36: Keeps the 2.56.35 canonical Google Fonts fix, but frontend/loopback requests now only reuse existing local font CSS and queue missing fonts for WP-CLI/server cron without synchronous downloads.
- 2.56.34: Coalesced Google Fonts background builds into a single queue/runner and made the runtime self-hosted font CSS map non-blocking on frontend requests.

### 2.56.55

- Added guarded callback timing summaries to STORE profile diagnostics for `wp_enqueue_scripts`, `template_redirect`, style/script loader filters, `wp_calculate_image_srcset`, and `shutdown`.
- Made repetitive settings getter checkpoints opt-in via `ucwp_store_profile_verbose_settings=1`, keeping normal diagnostic JSON smaller.

### 2.56.54
- Skips live frontend compression loopback probes during normal settings/default sanitization; cached/browser diagnostics remain available.

### 2.56.53

- Added opt-in early buffer/cacheability micro-profiling, limited to profiled requests, to isolate the remaining maybe_start_buffering → early_hit_check delay.
- Added checkpoints around should_bypass_cache(), engine settings loading, dashboard settings sanitization, and heavy support probes while preserving frontend behavior.
- Kept the opt-in STORE and deep request lifecycle profiler for rewrite-stage bytes/timings plus checkpoints across plugin load, dependencies, plugins_loaded priorities, setup_theme, after_setup_theme, init, wp_loaded, template_redirect, wp_head, output callback, cache write, and shutdown.
- STORE profiler can be triggered with the `X-UltraCache-Store-Profile: 1` request header or `?ucwp_store_profile=1`; the diagnostic query arg is stripped from the cache key.
- Added `wp ultracache store_profile show --format=json` and `wp ultracache store_profile clear`.
- Added CSS bundle diagnostics for user-controlled Inline CSS Bundling, including more robust inline style byte scanning, external bundle links, fallback counts, and manifest bundle file size.
- Added Populate Defaults buttons for visible safeguard/exclusion lists without forcing a full settings reset.
- Preserved the 2.56.43 Google Fonts architecture: no frontend live builds, no legacy queue recreation, no `google-fonts-pending`, and purge-all still preserves local Google Fonts cache.

### 2.56.40
- Frontend Google Fonts rewriting is read-only when local files are missing: it keeps the original Google Fonts URL and never creates legacy live-build queue data.
- Kept the validated 2.56.39 page-cache stampede lock behavior and the controlled dashboard/WP-CLI rebuild workflow.

### 2.56.39
- Added cold page-cache generation stampede protection so concurrent first hits wait for the first generated HTML cache instead of all rendering/storing independently.
- Settings save no longer depends on WP-Cron for Google Fonts cache rebuild; dashboard button/WP-CLI remain the controlled build paths.

### 2.56.38
- Local Google Fonts Optimization no longer discovers, queues, downloads, or builds Google Fonts assets from live frontend requests. Frontend HTML only rewrites Google Fonts links when the local CSS file already exists; otherwise it keeps the original Google Fonts URL intact.
- Enabling Local Google Fonts Optimization queues a homepage scan through the admin/server-cron path, not through the frontend request path.
- Added Advanced Settings & Exclusions → Additional URLs for Google Fonts scanning with Save Google Fonts URLs and Rebuild Google Fonts Cache controls.
- Rebuild Google Fonts Cache scans the homepage plus configured local URLs, clears/rebuilds only the Google Fonts cache, and downloads the discovered CSS/WOFF assets under wp-content/cache/ultracache/google-fonts/.
- Flush All Cache now preserves wp-content/cache/ultracache/google-fonts/ so local font files are not thrown away during normal page-cache purges.

### 2.56.36

- Kept the 2.56.35 canonical Google Fonts URL/hash behavior.
- Frontend and internal loopback requests now only reuse already-built local Google Fonts CSS; missing font CSS is queued for the real cron/WP-CLI runner instead of being downloaded during the page request.
- Added a short schedule lock so cold concurrency does not create duplicate Google Fonts build events.
- Left original Google Fonts URLs as fallback until the local files exist, so CSS integrity is preserved.


- Fixed the protocol-relative Google Fonts root cause where `//fonts.googleapis.com/...` and `https://fonts.googleapis.com/...` were treated as different hashes.
- Prevented `google-fonts-pending` from blocking page-cache storage; local font generation now stays best-effort and the frontend keeps the valid Google Fonts fallback.
- Kept the 2.56.34 baseline behavior and did not carry over the later experimental frontend CSS branches.

### 2.56.34

- Coalesced Google Fonts background builds into a single queue/runner.
- Made the runtime self-hosted font CSS map non-blocking on frontend requests.

### 2.56.28

- Added single-flight transient locks for Google Fonts CSS and font binary localization to prevent frontend PHP-FPM worker floods during cold cache generation.
- Google Fonts remote requests now use shorter timeouts and fall back to the original Google URLs while another request is already building the local cache.
- Replaced the cron warm-up schedule scan with `wp_next_scheduled()` to avoid walking the full WP-Cron array on dashboard/settings loads.

### 2.56.27

- Added hard single-flight locks for heavy dashboard actions and CSS/frontpage bundle generation.
- Stale dashboard action jobs are now failed automatically instead of blocking future actions.
- Internal loopback requests now carry UltraCache headers so on-entry CSS generation does not recursively amplify PHP workers.
- Pruned dashboard action queue storage to avoid stale running jobs and oversized options.

### 2.56.26

- Linked the Diagnostics and Activity Summary accordions so opening or closing either card toggles both together.
- Added the missing `.text-right` utility rule so status pills and right-aligned diagnostic text render correctly.

### 2.56.25

- Added the Query-string args whitelist Populate action for WooCommerce/taxonomy query keys.

### 2.56.24

- Stabilized page-cache variant creation: query-string HTML cache variants now require an explicit allowlist.
- Added a safety cap for same-path/same-bucket HTML variants to prevent runaway homepage cache files.
- Fixed Diagnostics and Activity Summary visibility so they remain visible when Cache Stats is disabled.

### 2.56.23

- Changed performance profile patches so no profile enables background/scheduled warm-up.
- Aggressive profile now keeps `cronWarmEnabled`, `cronWarmStartAfterCleanup`, and `cronWarmStartAfterManualPurge` disabled.
- Manual warm-up buttons remain unchanged.

### 2.56.21

- Blocked unsafe Varnish HTTP endpoints that point to the public WordPress frontend, especially `domain.com:80`, `domain.com:443`, and unsupported HTTP-mode ports.
- Changed the Varnish HTTP default endpoint to `127.0.0.1:82`; Admin mode remains `127.0.0.1:6082`.
- Added runtime guards so Varnish Test, Flush All, and URL purge refuse unsafe HTTP endpoints even if old options or imports contain them.
- Added diagnostics for old W3 Total Cache / Varnish helper leftovers before enabling Varnish or Object Cache.

### 2.56.19

- Consolidated version reporting to a single `UCWP_VERSION` source and removed the private hotfix bundle duplicate.
- Removed legacy REST namespace registration so only `ultracache/v1` is exposed.
- Removed old runtime secret path fallback loading and kept only the per-site secret file outside the webroot.
- Removed the old WP_CACHE marker normalization path; the current managed block is the only supported marker.

### 2.56.18

- Simplified Varnish HTTP endpoint handling to the current host:port-only model.
- Removed the legacy Varnish endpoint remap that silently changed old Varnish listener ports to the detected frontend endpoint.
- Added an admin-mode fallback endpoint of `127.0.0.1:6082` when admin mode is selected and the endpoints field is empty.

### 2.56.17

- Clarified the Varnish HTTP UI: endpoints are documented as host:port only, and PURGE no longer claims an automatic BAN fallback.
- Aligned the advanced-cache fallback defaults with the dashboard defaults for Woo safe mode and stale-while-revalidate.
- Fixed the runtime `defer_stage_safe` mapping so the computed safe-stage flag is written to config.

### 2.56.15

- Fixed a dashboard crash in Advanced Diagnostics caused by an undefined `objectFallbackActive` variable.
- Canonicalized saved dashboard settings on load so invalid previous combinations like Slider Safe Mode + LCP Boundary Defer are written back as valid settings.
- Restricted `data-ucwp-sr7-lcp` to SR7/RevSlider image candidates instead of allowing normal site images, such as the logo, to receive SR7-specific markers.
- Added generic `data-ucwp-lcp` markers for non-slider LCP image candidates.

### 2.56.09

- Hoisted CSS `@import` rules to the top of generated page CSS bundles so browser import ordering remains valid.
- Preserved a single CSS `@charset` rule at the very top of the generated bundle when source stylesheets include one.
- Rewrote relative `@import` URLs and normal `url(...)` references against each source stylesheet URL before bundling.
- Added an HTML `Content-Type` guard before `warm_url()` writes static HTML cache files or scans HTML for CSS bundling.
- Reduced per-page CSS warmups from `warm -> bundle -> warm` to `bundle -> warm`, avoiding the extra final loopback pass.

### 2.56.07

- Updated the Warm Cache button labels so they explicitly show Homepage, Shared, or Separate CSS Bundle behavior according to the selected CSS Bundling Scope.
- Homepage/shared scope CSS warm actions now build the homepage/shared CSS bundle once, then warm the selected URL set as HTML.
- Per-page scope CSS warm actions build separate CSS bundles for each warmed menu/full-site URL.

### 2.56.06

- Scoped the WordPress admin `#wpcontent` padding-left override to the UltraCache dashboard only.
- Removed the default WordPress left gutter from the UltraCache full-background admin UI while leaving all other wp-admin pages untouched.

### 2.56.05

- Added an **LCP Optimization** master switch under **Advanced Settings**.
- Gated **LCP Image Priority**, manual LCP image URL, and **LCP Boundary Defer** behind the new master switch while keeping backward compatibility for already-enabled installs.
- Added SR7/Revolution Slider hashed asset discovery for generated `/revslider/o/` images, with preference for existing UltraCache AVIF/WebP cache variants.
- LCP Boundary Defer can now use the detected SR7 module boundary as a conservative visual boundary, while existing protections still apply: WordPress core, WooCommerce core, Elementor, sliders, dependencies, and manual exclusions stay protected.

### 2.56.02

- WP-CLI: `wp ultracache purge --all` is now accepted as an explicit full-cache purge alias for `wp ultracache purge`.
- Settings cleanup: deprecated local-build keys no longer leak into CLI/settings output (`cronWarmStartAfterFlush`, `warmAfterScheduledCleanup`, `avifConversionEnabled`).
- No tracking-query default changes were made.

### 2.56.00

- Expanded parser-assisted HTML rewrite refactor across script/link asset cleanup and LCP image handling.
- Added parser-assisted script/link removal for asset cleanup and frontend authoring asset stripping.
- Improved LCP image candidate handling for `srcset`/`source`-style candidates and parser-backed priority attributes.
- Kept legacy regex fallbacks and HTML rewrite safety bailouts active.

### 2.55.98

- Refactored stylesheet/link rewrite paths to prefer `WP_HTML_Tag_Processor` where possible.
- Preserved legacy fallbacks and safe `noscript` stylesheet fallbacks.

### 2.55.97

- Added deeper parser-assisted preload duplicate checks and shared safe head injection helpers.

### 2.55.96

- Removed theme-specific built-in JS protections. Site/theme-specific issues should be solved with manual exclusions.

### 2.55.90

- Removed border styling from diagnostic/status text pills and value rows.
- Renamed CSS Bundle Diagnostics to CSS Bundle Summary and added it to Activity Summary.

### 2.55.88

- Cleaned Media Optimization naming so `mediaOptimizationEnabled` is the master switch and output mode controls AVIF/WebP/Auto policy.

### 2.55.84

- Added Disk Object Cache and Varnish Admin mode warnings.
- Added analytics backend live probe for APCu/Redis.

### 2.55.81

- Changed analytics hits to APCu → Redis → disabled.
- Changed object cache strategy to Redis → APCu → runtime-only, with Disk only as explicit advanced/debug mode.

## License

UltraCache is licensed under the **GNU General Public License v2, or any later version**.
