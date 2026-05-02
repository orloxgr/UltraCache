# UltraCache

UltraCache is a WordPress performance plugin for site owners and operators who want practical caching controls, visible safeguards, cache warm-up, object cache support, media optimization, CSS/font optimization, Varnish-aware purge tools, and diagnostics that explain what is happening.

Current version: **2.56.171**

Release notes are maintained in [`changelog.txt`](changelog.txt).

## What UltraCache does for users

UltraCache helps a WordPress site respond faster by reducing repeated PHP/WordPress work, serving eligible anonymous pages from cache, warming important URLs, optimizing image/font/CSS delivery, and showing cache-stack diagnostics in one dashboard.

Typical uses:

- Cache public pages as static HTML.
- Warm homepage, menu URLs, and full-site HTML cache.
- Use Redis or APCu as object-cache backends.
- Integrate with Varnish-aware purge workflows.
- Convert media to AVIF/WebP when the server supports it.
- Build CSS bundles and show what was bundled, skipped, or unresolved.
- Localize Google Fonts and optimize self-hosted font CSS.
- Add LCP image priority and slider/hero support.
- Delay/defer eligible JavaScript with visible exclusions.
- Inspect storage, cache headers, object cache backend, Varnish, OPcache/APCu, media queue, CSS bundle status, and STORE profiles.

## Supported caching and performance technologies

| Area | Support | Notes |
| --- | --- | --- |
| HTML page cache | Yes | Managed through `advanced-cache.php` and `wp-content/cache/ultracache/`. |
| Redis object cache | Yes | Recommended persistent object-cache backend when Redis is available. |
| APCu object cache | Yes | Local single-server fallback. Cleared when PHP-FPM restarts. |
| Runtime-only object cache | Yes | Safe fallback when Redis/APCu are unavailable. |
| Disk object cache | Advanced/debug only | Explicit option; not recommended as normal production default. |
| Varnish | Optional integration | HTTP endpoint/admin-secret workflows for local/private Varnish setups. |
| OPcache | Diagnostics | UltraCache shows OPcache visibility but does not replace PHP OPcache. |
| Server gzip/Brotli | Detection/coordination | Avoids unnecessary duplicate compression where applicable. |
| AVIF/WebP | Yes | Depends on Imagick/GD codec support. |
| Google Fonts/local fonts | Yes | Remote Google Fonts cache plus self-hosted font CSS optimization. |

## Quick Guide

### 1. Start with a safe profile

1. Open the UltraCache dashboard.
2. Choose a safe baseline profile.
3. Enable **Page Cache**.
4. Save settings.
5. Purge all cache.
6. Warm the homepage/menu cache.
7. Visit the homepage twice as a logged-out visitor and confirm that the second request can be served from cache.

### 2. Add object cache information

1. Open Object Cache settings.
2. Prefer Redis when available.
3. Enter Redis host, port, password/database/TLS/persistent settings when needed.
4. Test connection.
5. If Redis is unavailable, use APCu when supported.
6. Use Disk only for advanced/debug testing.

Recommended object-cache order:

1. Redis
2. APCu
3. Runtime-only fallback
4. Disk only when explicitly selected for advanced/debug testing

### 3. Run profiler and diagnostics

Use Speed Diagnostics / STORE profiler when a MISS/STORE request is slow or when frontend rewrites appear expensive.

Review server response timing, STORE profile timing, frontend rewrite stages, CSS Bundle Summary, object-cache backend truth, Varnish/reverse-proxy status, storage diagnostics, and media queue health.

### 4. Tune CSS with exclusions

1. Start with Safe CSS Bundle mode.
2. Warm homepage with CSS bundle generation.
3. Review CSS Bundle Summary.
4. Add visible exclusions for stylesheets that break layout or should not be bundled.
5. Test homepage, product page, cart, checkout, search, menu, sliders, fonts, and mobile layout.
6. Use aggressive CSS modes only after visual testing.

### 5. Tune JavaScript with visible safeguards

1. Use JS Delay / Defer Exclusions for files, handles, or globals that must keep their order.
2. Avoid blindly deferring jQuery, WooCommerce core dependencies, Elementor runtime, or active above-the-fold slider scripts.
3. Check the browser Console after every defer/delay change.
4. Keep site-specific safeguards visible and editable.

### 6. Optimize media and fonts

1. Enable Media Optimization only after AVIF/WebP support is confirmed.
2. Use the AVIF/WebP Batch Conversion box to start/resume conversion.
3. Use Local Google Fonts Optimization for remote `fonts.googleapis.com` stylesheets.
4. Use Self-hosted Font CSS Optimization for local/theme/Elementor font CSS.
5. Rebuild Google Fonts cache after changing scan URLs.

## WP-CLI commands

Run WP-CLI as the site owner when possible. Avoid `--allow-root` as the normal workflow.

| Command | Purpose |
| --- | --- |
| `wp ultracache cleanup` | Run scheduled cleanup. |
| `wp ultracache cron_warm` | Inspect or process the cron warm queue. |
| `wp ultracache css_diagnostics` | Show CSS/request-chain diagnostics. |
| `wp ultracache flush_object_cache` | Flush the managed object cache. |
| `wp ultracache google_fonts_rebuild` | Rebuild the local Google Fonts cache. |
| `wp ultracache inspect` | Inspect cacheability for a local URL. |
| `wp ultracache media` | Manage AVIF/WebP media conversion queue. |
| `wp ultracache purge` | Purge full cache or a local URL. |
| `wp ultracache self_test` | Run internal smoke/self checks. |
| `wp ultracache settings` | Read or update dashboard settings. |
| `wp ultracache stats` | Read or reset cache statistics. |
| `wp ultracache status` | Show cache/drop-in/storage status. |
| `wp ultracache store_profile` | Show or clear the last STORE profile. |
| `wp ultracache varnish` | Test or trigger Varnish helpers. |
| `wp ultracache warm` | Warm cache URLs. |
| `wp ultracache warm_frontpage_html` | Warm homepage HTML cache. |
| `wp ultracache warm_frontpage_html_css` | Warm homepage HTML cache plus CSS bundle. |
| `wp ultracache warm_html_all` | Warm full-site HTML cache. |
| `wp ultracache warm_html_all_css` | Warm full-site HTML cache plus CSS bundles. |

Examples:

```bash
wp ultracache status --format=json
wp ultracache status --section=storage --format=json
wp ultracache purge --all
wp ultracache purge --cache-url=https://example.com/
wp ultracache warm_frontpage_html_css
wp ultracache media status --media-format=best --format=json
wp ultracache media process --media-format=best --limit=5 --time-budget=20 --format=json
wp ultracache google_fonts_rebuild --clear
wp ultracache flush_object_cache
wp ultracache store_profile show --format=json
```

## Troubleshooting quick guide

### Cache never HITs

Check that Page Cache is enabled, `WP_CACHE` is true, `advanced-cache.php` exists, the request is anonymous/public, no cart/session/login cookies are present, and the URL/query string is not excluded.

### Object cache is not active

Check the selected backend vs active backend in Diagnostics. For Redis, verify extension, host, port, credentials, database, TLS, and timeout settings. For APCu, verify that the extension is enabled for the PHP runtime.

### Varnish purge does not work

Use local/private Varnish endpoints only. Check whether the site uses HTTP endpoint mode or admin-secret mode, then verify the effective purge method in Diagnostics.

### Layout breaks after CSS changes

Check CSS Bundle Summary, skipped/unresolved stylesheet count, missing CSS files in browser Network, and whether the issue disappears when CSS bundling/async CSS is disabled. Add visible CSS exclusions for the exact problematic stylesheet.

### JavaScript errors after defer/delay

Check browser Console. Add only site-specific theme/custom scripts, handles, or globals to JS Delay / Defer Exclusions. Avoid hard-coding theme-specific protections into the plugin.

### Google Fonts cache says it is not built

Run Rebuild Google Fonts Cache. If no remote `fonts.googleapis.com` stylesheets exist on the scanned pages, no local Google Fonts cache may be needed for those pages. Self-hosted/theme/Elementor font CSS is handled by the separate self-hosted font CSS optimization pipeline.

## Post-update checklist

1. Save UltraCache settings once.
2. Purge all cache.
3. Warm the homepage/menu cache.
4. Visit important pages logged out.
5. Check cache headers.
6. Check browser Console for JavaScript errors.
7. Check browser Network for missing local assets.
8. Test homepage, product page, cart, checkout, search, menu, sliders, fonts, and mobile layout.
9. Review Diagnostics for page cache, object cache, Varnish, storage, media queue, CSS Bundle Summary, and generated drop-in versions.

## License

UltraCache is licensed under the **GNU General Public License v2, or any later version**.
