# UltraCache

UltraCache combines WordPress page caching, object-cache integration, AVIF/WebP media rewrite, frontend optimization, warm-up tools, Varnish helpers, and diagnostics in one administrator-controlled plugin.

Version: `2.59.09.68`
Requires WordPress: `6.9` or newer  
Requires PHP: `8.1` or newer  
License: GPL-2.0-or-later

## Main features

- Anonymous public-page HTML cache with explicit bypass rules and optional Apache Static HTML Delivery.
- Optional automatic page-cache and frontend-asset invalidation after successful WordPress core, active plugin, and active parent/child theme updates, without flushing object cache or optimized images.
- Managed `advanced-cache.php` and `object-cache.php` WordPress drop-ins.
- Redis, APCu, SQLite, runtime-only, and advanced disk object-cache backends.
- AVIF/WebP generation and frontend media URL rewriting with primary/fallback output policies and a shared compression level.
- Batch, upload, and background-queue media processing, sample conversion comparisons, and opt-in regeneration of existing optimized images.
- Resumable Media Library Replacement for attachment originals and generated sizes, including verified metadata, database-reference, and Theme CSS updates.
- CSS bundling, async CSS, Google Fonts localization, and font CSS optimization.
- JavaScript defer/delay with editable exclusion controls.
- LCP priority with exact manual CSS selectors, SVG image support, optional timed browser-based frontend discovery, persistent per-page and per-viewport learning, targeted page-cache refresh through the existing warm queue, lazy loading, and optional CLS dimensions.
- Homepage, menu, selected-URL, and full-site warm-up.
- Optional administrator-configured Varnish integration with public cache-behavior tests, topology-aware verified HTML-only flushing, bounded persistent invalidation/refill queues, shared TTL and stale refresh controls, conditional ETag/Last-Modified revalidation, manual Varnish prewarm, and capability-gated hot-page refresh ahead, bounded endpoint health/latency metrics, queue retry counters, and authenticated admin-mode ban-pressure inspection.
- Dashboard and WP-CLI diagnostics, including persistent browser-observed LCP mapping and warm-refresh inspection.

## Storage and WordPress drop-ins

UltraCache stores generated assets and runtime cache data below the resolved WordPress uploads directory in `uploads/ultracache/`. Browser-observed LCP mappings are stored separately in the WordPress database table `{prefix}ultracache_lcp_observations`. LCP Frontend Discovery can run for public visitors or only administrators for a selected duration. The first valid observation becomes active immediately, while a rolling two-of-three confirmation locks each page and viewport and stops further reporting for that scope. URLs with query parameters are excluded. Exact manual selectors take precedence, confirmed mappings remain active until explicitly relearned or forgotten, one winner per page and viewport can emit a preload, and page-specific refresh jobs remain in the existing cron warm queue table.

WordPress requires `advanced-cache.php` and `object-cache.php` directly in the active content directory. UltraCache manages those files there only when their related features are enabled.

## Page cache

The page cache targets anonymous public requests. Logged-in users, administration paths, cart/checkout/account flows, unsafe cookies, and configured exclusions are bypassed. Query-string variants require an explicit allowlist containing every query key; an empty allowlist bypasses query-string caching.

The Cache Engine can purge page HTML and invalidate generated frontend asset maps after successful WordPress core updates, active plugin updates, and updates to the active child or parent theme. Bulk plugin/theme operations trigger at most one purge, inactive plugin/theme updates are ignored, and this path does not flush object cache or optimized-media storage.

## Object cache

The recommended object-cache order is Redis, APCu, SQLite for persistent local storage on single-server sites, runtime-only fallback, and Disk only for advanced or diagnostic use. The dashboard reports the configured backend, the backend actually in use, and any active fallback. When SQLite is selected, its main database-file limit is configurable from 32 MB to 2048 MB and defaults to 256 MB.

## Media Rewrite and conversion

Media Rewrite changes frontend image URLs to generated AVIF/WebP variants when those files exist and the selected policy permits them. The Image Output Format selects the primary format, the Fallback Format controls the compatible secondary output, and the shared Image compression level applies across media optimization, upload generation, and Media Library Replacement.

WebP is the default for fresh installations. AVIF is enabled only after UltraCache validates bundled opaque and transparent encode/decode regression tests. Frontend requests remain lookup-only; encoding runs through upload processing, batch conversion, or background queue workers. The dashboard also includes sample WebP/AVIF comparisons and opt-in regeneration of existing optimized images with the current quality setting.

## Media Library Replacement

Media Library Replacement promotes verified UltraCache-generated AVIF/WebP rewrite files into the WordPress Media Library. It covers attachment originals and registered intermediate sizes, updates attachment metadata, and can replace matched media references in current-install database tables and active parent/child theme CSS files.

## CSS, JavaScript, and fonts

The plugin includes CSS bundling, async CSS controls, JavaScript defer/delay, Google Fonts localization, self-hosted font CSS optimization, delayed icon-font loading, and optional runtime font CSS rewriting. Features that can affect layout or timing have visible controls and exclusions.

## Installation

1. Upload and activate UltraCache.
2. Open the dashboard from the WordPress admin menu or admin bar.
3. Select the **Aggressive** profile and save the settings.
4. Run the **HTML Compression** check under Cache Engine.
5. In **Warm Cache**, select the main frontend menu and the required depth. First-level menu URLs suit most websites.
6. Select the required **Full-site warm-up sources**. Homepage / blog index, Selected menu URLs, Pages, Posts, and Categories cover most sites.
7. In **Media Library Replacement**, enable **Convert new uploads**, set **Maximum upload image side** to `1920` unless larger source images are required, and choose the image output/fallback formats.
8. Select **Compact** under Image compression level. When using AVIF, run **Image conversion test** and then **Check test**.
9. In **Fonts Optimization**, enable **Local Google Fonts Optimization**, **Bundle Generated Font-Mix CSS**, and **Delay icon fonts**.
10. For WooCommerce, enable **Suppress empty-cart execution**. For MailerLite, enable **Lazy MailerLite nonce refresh**.
11. Confirm the detected Object Cache backend and configure Varnish when present.
12. In **Automation & Scheduling**, enable **Cron Warm Up** and **Start Cron Warm Up after Scheduled Cleanup**.
13. Save the settings and run **Flush All Cache**.

For the first full preparation, run **Warm Up Menu HTML Cache + Separate CSS Bundles**, then use **Start / Resume Conversion** under AVIF / WebP Batch Conversion for existing Media Library images. Run Full-site warm-up when the complete selected URL set should be cached.

### Must-do post-install check

1. Open the public website in a private window: `Ctrl+Shift+N` in Chrome or Edge, or `Ctrl+Shift+P` in Firefox.
2. Press `F12`, open the Console, and reload the page.
3. If no JavaScript errors appear, the check is complete. If errors appear, copy the red error lines and stack traces.
4. Open **JS Defer / Delay Safeguards & Diagnostics** in UltraCache.
5. Paste the errors into **Console Error Handler** and click **Extract Console Error Suggestions**.
6. Append the proposed fixes to **Defer Instead** or **Do Not Defer or Delay**, then click **Save Both Lists**.
7. Run **Flush All Cache** and warm the front page again. Front-page warm-up is enough while testing.
8. Repeat the check until the Console loads without JavaScript errors.

The fixed **Help for the installed version** button in the lower-right corner of the dashboard opens the detailed setup, post-install guide, and complete FAQ for the installed release.


## WP-CLI examples

```bash
wp ultracache status --format=json
wp ultracache purge --all
wp ultracache warm_frontpage_html_css
wp ultracache media status --media-format=both --format=json
wp ultracache flush_object_cache
```

## External services

UltraCache does not require an external SaaS account and does not send visitor data to an UltraCache-owned service.

### Google Fonts

When Local Google Fonts Optimization is enabled, the WordPress server may request configured CSS and font resources from `fonts.googleapis.com` and `fonts.gstatic.com` to create local copies.

- Service: https://fonts.google.com/
- Terms: https://policies.google.com/terms
- Privacy: https://policies.google.com/privacy
- Privacy FAQ: https://developers.google.com/fonts/faq/privacy

### Third-party matching rules

Editable matching examples can identify scripts already added by a site, theme, or another plugin. They control delay, defer, or exclusion behavior only; they do not install or contact those services.

### PayPal support link

PayPal is contacted only when an administrator chooses to open the optional dashboard support link.

- Service: https://www.paypal.com/
- Terms: https://www.paypal.com/us/legalhub/paypal/useragreement-full
- Privacy: https://www.paypal.com/us/legalhub/paypal/privacy-full

## Deactivation and cleanup

Before individual deactivation from the standard WordPress Plugins screen, UltraCache lets an administrator select the cleanup policy that will apply if the plugin is later deleted. Policies can retain settings and custom tables or remove plugin runtime/cache data. Converted media files are retained by design.

## Privacy

UltraCache stores cache files, generated assets, settings, queue records, and diagnostics locally on the WordPress installation. It does not track visitors through an external UltraCache service.
