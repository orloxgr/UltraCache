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

- Version: `2.56.43`
- Build type: Google Fonts admin-scan polish and resource-hint cleanup
- Runtime focus: frontend Google Fonts remains read-only; local font cache status is visible in diagnostics; remote Google Fonts resource hints are removed after successful local rewrite
- Default behavior: Flush All Cache preserves the Google Fonts cache; Google Fonts are rebuilt only from the dashboard button or WP-CLI.

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

Do not hard-code theme-specific dependencies into the plugin. If a theme or custom script has a dependency-order issue, add it to **Defer / Delay Exclusions** manually.

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

Check browser Console and add only site-specific theme/custom scripts to manual **Defer / Delay Exclusions**. Avoid hard-coded theme protections in the plugin.

### CSS/layout issue

Check:

- CSS Bundle Summary.
- Skipped/unresolved stylesheet count.
- Browser Network for missing CSS files.
- Whether the issue disappears when CSS bundling/async CSS is disabled.

## Changelog

- 2.56.43: Adds Google Fonts cache status diagnostics, removes remote Google Fonts DNS/preconnect hints after successful local rewrite, and keeps rebuilds dashboard/WP-CLI controlled only.
- 2.56.40: Hardens the 2.56.39 Google Fonts admin-scan workflow by making legacy live-build queues/events cleanup-only, keeping frontend missing-font handling read-only, and preserving the page-cache stampede lock behavior.
- 2.56.39: Adds page-cache stampede protection for cold concurrent requests, keeps Google Fonts rebuilding manual/admin-controlled without frontend live builds, and avoids leaving server-cron-only Google Fonts rebuild events after settings save.
- 2.56.38: Moves Local Google Fonts Optimization to a controlled admin/save/manual-rebuild scan pipeline, stops frontend Google Fonts build/queue behavior, adds Additional URLs for scanning, and preserves the Google Fonts cache during Flush All Cache.
- 2.56.36: Keeps the 2.56.35 canonical Google Fonts fix, but frontend/loopback requests now only reuse existing local font CSS and queue missing fonts for WP-CLI/server cron without synchronous downloads.
- 2.56.34: Coalesced Google Fonts background builds into a single queue/runner and made the runtime self-hosted font CSS map non-blocking on frontend requests.

### 2.56.43

- Added Google Fonts cache status diagnostics for stylesheet/font counts and built/not-built messaging.
- Removed remote Google Fonts dns-prefetch/preconnect hints from cached HTML once the stylesheet is successfully rewritten to the local UltraCache Google Fonts cache.
- Kept Google Fonts cache generation explicit-only through the dashboard rebuild button or `wp ultracache google_fonts_rebuild --clear`.
- Flush All Cache continues to preserve the local Google Fonts cache.

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
