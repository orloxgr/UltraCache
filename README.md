# UltraCache

UltraCache is a production-oriented WordPress performance plugin focused on page-cache delivery, cache warming, object caching, media conversion, Redis-backed object caching, Varnish-aware purge workflows, and practical operator tooling.

It is built for sites that want a managed page-cache drop-in, optional Redis-backed object cache, optional Varnish integration, image format conversion, and a diagnostics-heavy admin experience without relying on external build tooling.

---

## Current repository build

- version: `2.55.80`
- latest pass: safer fresh-install defaults for page cache, object cache, and Browser Cache Headers; backward-compatible Media Optimization aliasing; safer .htaccess warning behavior
- sixth pass fixes: removed hard page reloads after Varnish Test and Flush Varnish All; the dashboard now stays on the page and refreshes through AJAX only
- fifth pass fixes: reduced `@` suppression in non-critical helper paths and added safer internal debug logging for font CSS reads and Varnish admin socket connects
- third pass fixes: redacted secrets from `wp ultracache status` settings/all output, added stricter local-site URL validation for single-URL CLI actions, and tightened REST validation for settings enums and URL/scope inputs
- first pass fixes: removed auto-opening support modal, redacted secrets from WP-CLI settings output, added textdomain loading, and changed Local Google Fonts Optimization to opt-in by default for fresh installs
- second pass fixes: tightened REST schema coverage for settings/Redis/cron inputs and improved support modal accessibility metadata

---

## 2.55.73 notes

- Disabled Warm Cache actions until Page Caching is enabled.
- Disabled CSS-bundle warm actions until CSS Bundling is enabled.
- Added REST guards for direct warm/CSS bundle requests when required features are off.

## 2.55.61 notes

- Removed forced all-caps dashboard styling and utility usage.
- Added 5px spacing above the Start Media Conversion action row.
- Added 5px left spacing to dashboard toggles.
- Added 10px top spacing to the Advanced Settings & Exclusions textarea grid.
- Suppressed Elementor Notes admin assets on the UltraCache dashboard to avoid React global console errors from notes.min.js and notes-app-initiator.min.js.

---

## Main feature areas

### Full-page cache

UltraCache stores cacheable public pages as static files under:

`wp-content/cache/ultracache/`

The plugin manages a custom `advanced-cache.php` drop-in that can serve those files early in the WordPress bootstrap.

Supported page-cache behaviors include:

- public anonymous `GET` and `HEAD` requests
- local-site URL warming
- stale-while-revalidate delivery
- cache buckets for `orig`, `webp`, and `avif`
- optional compressed sidecar files when supported by the server

### Object cache

UltraCache can manage an `object-cache.php` drop-in and use either:

- disk-backed object storage
- Redis-backed object storage

Redis support requires the PHP Redis extension.

### Media conversion

UltraCache can generate next-gen variants for uploaded images and WordPress-generated sizes.

Supported output targets:

- AVIF
- WebP fallback

Whether AVIF or WebP is actually available depends on the PHP/image stack on the server.

### Frontend optimization features

The current codebase also includes optional frontend optimization switches such as:

- cumulative Defer Stages for JavaScript optimization
- shared JS defer-stage exclusions
- delay third-party JS and selected local enhancement scripts
- async external scripts
- async CSS support
- image dimension injection for CLS reduction
- LCP image prioritization
- Google Fonts `display=swap`
- localized Google Fonts stylesheet caching (opt-in; fetches Google Fonts assets only when enabled)
- self-hosted font CSS normalization
- speculation rules output
- browser cache rules for `.htaccess`

### Warmup and cleanup

UltraCache supports:

- manual warmup
- scheduled cleanup
- cron warm-up queue execution
- front-page HTML warming
- homepage/page CSS scan + bundle generation

### Reverse-proxy integration

UltraCache includes optional Varnish HTTP support for:

- connection tests
- flush-all actions
- per-URL purge/ban workflows

---

## Admin dashboard

The admin dashboard is designed for operators and site maintainers.

Major dashboard areas include:

- cache analytics
- diagnostics
- cache engine toggles
- compression/image/object-cache settings
- Redis connection settings
- Varnish settings
- warmup controls
- cache decision tester
- rules and scheduling
- export/import settings

Diagnostics cover items such as:

- drop-in status
- cache directory status
- reverse-proxy detection
- recent cache-write activity
- object-cache backend status
- Varnish state
- runtime configuration state

---

## REST API

UltraCache ships with admin-facing REST endpoints under:

`/wp-json/ultracache/v1/`

The current codebase includes routes for actions such as:

- `stats`
- `settings`
- `purge-all`
- `crawl-urls`
- `crawl-page`
- `inspect-url`
- `build-frontpage-css`
- `warm-frontpage-html`
- `warm-frontpage-html-css`
- `media-ids`
- `optimize-id`
- `optimize-media`
- `object-cache/redis-test`
- `object-cache/flush`
- `varnish/test`
- `varnish/flush-all`
- `cron-warm/start`
- `cron-warm/stop`
- `cron-warm/tick`

These routes are intended for the admin application and require administrative capability checks.

---

## WP-CLI

UltraCache registers the `wp ultracache` command.

Available command groups in the current build include:

- `purge`
- `warm`
- `warm-html-all`
- `warm-frontpage-html`
- `warm-frontpage-html-css`
- `warm-html-all-css`
- `media`
- `status`
- `inspect`
- `settings`
- `stats`
- `varnish`
- `cron_warm`
- `cleanup`

Typical examples:

```bash
wp ultracache purge
wp ultracache purge --cache-url=https://example.com/some-page/

wp ultracache warm
wp ultracache warm --cache-url=https://example.com/
wp ultracache warm --limit=100
wp ultracache warm --buckets=orig,webp,avif

wp ultracache warm-frontpage-html
wp ultracache warm-frontpage-html-css

wp ultracache media optimize --limit=100
wp ultracache inspect https://example.com/
wp ultracache stats --format=json

wp ultracache cron_warm start
wp ultracache cron_warm status
wp ultracache cron_warm tick

wp ultracache varnish test
wp ultracache varnish flush-all
```

Run `wp help ultracache` for command-specific options in your installed build.

---

## Files and directories managed by the plugin

Depending on settings, UltraCache may create or manage:

- `wp-content/advanced-cache.php`
- `wp-content/object-cache.php`
- `wp-content/cache/ultracache/`
- `wp-content/cache/ultracache-avif/`
- `wp-content/cache/ultracache-webp/`
- `wp-content/cache/ultracache-objects/`
- front-page CSS bundle files under the cache directory
- Google Fonts cached stylesheets/binaries under the cache directory

The plugin may also update:

- `wp-config.php` to manage `WP_CACHE`
- `.htaccess` when browser cache rules are enabled

---

## WooCommerce behavior

UltraCache includes WooCommerce-safe bypass logic to avoid caching dynamic customer flows.

Typical excluded areas include:

- cart
- checkout
- my account
- order payment / order received flows
- payment-method flows
- lost-password flows
- common add-to-cart and coupon query arguments
- common login/session/cart cookies

---

## Installation

1. Upload the plugin to `wp-content/plugins/`.
2. Activate **UltraCache** from WordPress admin.
3. Open the **UltraCache** admin page.
4. Review diagnostics before enabling page cache or object cache.
5. If using Redis, confirm the PHP Redis extension is available.
6. If using AVIF/WebP generation, confirm your Imagick or GD stack supports the required formats.

---

## Recommended rollout order

For production sites, a conservative rollout is recommended:

1. Activate the plugin.
2. Review Diagnostics.
3. Enable page cache first.
4. Test purge and warm flows.
5. Enable media conversion if supported.
6. Enable Redis object cache only after validating compatibility.
7. Enable advanced frontend optimizations one by one.
8. Re-check diagnostics and front-end behavior after each change.

---

## Notes

- This plugin is designed for real-world operators and server-aware WordPress environments.
- Redis, AVIF, WebP, Brotli, and Varnish features depend on server support.
- Some features intentionally modify drop-ins or runtime files and should be tested carefully on staging first.

---

## License

UltraCache is licensed under the **GNU General Public License v2, or any later version**.

In short, you may use, modify, and redistribute this plugin under the terms of **GPLv2 or later**. This is the same license model recommended for WordPress plugins and is fully compatible with the WordPress ecosystem.

- Added frontend compression detection so Gzip/Brotli toggles stay disabled when the server already applies them by default.

- Automatically turns off Gzip/Brotli in the dashboard when frontend compression is already handled by the server or proxy.
- Added a Reset Settings button beside Export/Import.

- Reduced additional @ suppression in non-dropin helper paths.
- Added safe wrappers for filesize(), tempnam(), and fread() in non-critical helpers.

- latest patch: moved Redis auth out of generated `object-cache.php` into a protected sidecar config file with restrictive permissions


## 2.55.46 notes

- Added SR7/Revolution lifecycle-aware LCP priority handling that listens for `sr.module.ready` and marks first-slide image layers without relying on generated layer IDs.
- Kept the SR7 priority fix controlled by the visible LCP Image Priority and Fix sliders / hero sections settings; it is not a hidden optimization.
- Renamed confusing dashboard fields: Manual Priority Preloads, Additional Fetch URL Preloads, and Single LCP Image URL.

- Added ID-independent SR7 first-slide LCP priority handling, so Revolution Slider image layers can receive `fetchpriority="high"` without relying on dynamic IDs such as `SR7_1_1-1-8`.
- Added a tiny runtime guard for SR7 layers generated after the initial HTML parse.
- Prevents generated SR7 `/revslider/o/` image-list placeholders from being manually preloaded when Slider Safe Mode or LCP Image Priority is active, avoiding Chrome `preloaded but not used` warnings.

## 2.55.44 notes

- Made CSS Bundle output fail-safe after the 2.55.38 frontend blank-page regression.
- Stage 1 now injects the bundle non-destructively and keeps the original stylesheet links as the authoritative fallback.
- Stage 2 still replaces matching source stylesheet links, but keeps async preload/noscript fallbacks for the original stylesheets.
- Hardened the page CSS bundle replacement path so it verifies the generated bundle file before touching the document head and reconstructs the head without `preg_replace()` replacement-string side effects.
- Moved **Cache Engine Advanced settings and exclusions** into its own one-column accordion card below the main Cache Engine / Compression settings grid.

## 2.55.33 notes

- Hardened the delayed JS loader so it preserves script attributes through a compact encoded attribute map.
- Added safer delayed execution with sequential loading, an 8-second per-script fallback timeout, and compatibility fallbacks for older placeholders.
- Added dependency safeguards so local scripts with enqueued dependents are not delayed.
- Expanded built-in delay exclusions for builders, menus, sliders, video players, popups, forms, and fragile frontend runtimes.

## 2.55.32 notes

- Moved runtime self-hosted font CSS rewriting behind a separate advanced toggle and removed native DOM prototype monkey-patching from the default path.
- Made Asset Chain Cleanup more conservative with built-in/request/HTML exclusions and a dashboard-managed exclusion list.
- Narrowed product-filter asset cleanup to plugin-specific handles/paths instead of broad fragments such as tooltipster, icheck, html_types/slider, or by_sku.
- Cleaned up legacy/mapping issues for scheduled cleanup warming and media-converter settings fallbacks.

## 2.55.31 notes

- Added frontend on-demand image conversion safeguards: per-image/per-format lock files, per-request conversion limits, and a short request-time budget before starting new conversions.
- Kept Generate on Demand as the explicit dashboard control, but restricted runtime generation to safe frontend GET/HEAD requests.

## 2.55.30 notes

- Analytics hot path cleanup: page-cache HIT counters are now buffered and flushed in batches instead of reading/writing analytics.json on every cached response.
- APCu is used for in-memory hit counters when available; a compact file buffer is used as a fallback and dashboard/CLI reads flush pending counters before reporting stats.

## 2.55.29 notes

- Added native `rename()` fallback to `ucwp_safe_rename()` so atomic replacements do not depend only on `WP_Filesystem`.
- Added native recursive directory deletion fallback to `ucwp_safe_rmdir()` for cache cleanup paths when `WP_Filesystem` cannot remove directories.
- Tightened page-cache atomic write verification so failed replacements do not silently look successful.
- Hardened full-cache purge recursion by using safe directory scans and avoiding recursion into symlinks.

## 2.55.28 notes

- Fixed Redis object-cache drop-in bootstrap so the protected Redis secret config is loaded before connecting/authenticating.
- Propagated Redis TLS and persistent-connection settings into the generated object-cache drop-in.
- Fixed disk object-cache reads so signed payloads that contain WordPress objects can be restored instead of being rejected.
- Moved `wp_suspend_cache_addition()` handling before runtime cache mutation so suspended additions do not pollute the in-request cache.

## 2.55.27 notes

- Separated JS Defer and Delay controls so third-party delay, non-critical/local delay, and native defer no longer silently enable one another.
- Fixed the runtime mapping that incorrectly enabled non-critical/local JS delay when only third-party delay was enabled.

## 2.55.26 notes

- Added Critical Request Chain Relief with manual preload and chain-delay controls.
- Added Asset Chain Cleanup for WooCommerce/product-filter request chains.
- Frontend Safe Mode OFF now remains fully off.

## 2.55.44 notes

- Restores Stage 1 CSS Bundle to the legacy site-wide behavior used before the 2.55.38 CSS bundle stage/page-entry changes.
- Keeps page-entry CSS bundle generation and the newer page-wide replacement path behind Stage 2 / Aggressive mode only.
- This avoids changing the safe stage while retaining the new Stage 2 experimentation path.

## 2.55.44 notes

- Added **Fix sliders / hero sections** in Cache Engine Advanced settings and exclusions.
- When enabled, UltraCache detects Revolution Slider/SR7 and common hero slider markup and keeps that page closer to WordPress default frontend loading.
- Slider-safe responses skip CSS bundle replacement, async CSS rewriting, asset cleanup, self-hosted font CSS rewriting, LCP/CLS structural mutations, and HTML minification for that response.

## 2.55.44 notes

- Stage 2 CSS Bundle is now more aggressive: bundled source stylesheets are replaced by the generated bundle without preloading every original source.
- Slider Safe Mode is now slider-aware for CSS bundles: SR7/Revolution/Swiper/Slick CSS stays as normal explicit links, while non-slider local CSS can still be bundled.
- Built-in CSS bundle exclusions now protect fragile slider/hero stylesheet fragments.
