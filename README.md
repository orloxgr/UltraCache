# UltraCache

UltraCache is a production-oriented WordPress performance plugin focused on page-cache delivery, cache warming, Redis/APCu object caching, Varnish-aware purge workflows, media optimization, CSS bundle generation, and operator-friendly diagnostics.

## Varnish Support and cache-backend compatibility

| Feature / backend | Status | Recommended use | Notes |
| --- | --- | --- | --- |
| **Varnish Support** | Optional integration | Use when the site is behind Varnish and you want UltraCache to test, purge, or ban cached pages. | HTTP mode targets frontend BAN/PURGE endpoints. Admin mode should use localhost/private endpoints only, for example `127.0.0.1:6082`. Do not expose the Varnish admin port publicly. |
| **Redis Object Cache** | Recommended production backend | Best persistent object-cache backend when the PHP Redis extension and a stable Redis service are available. | UltraCache stores Redis secrets in protected runtime sidecar config rather than exposing them in generated drop-ins. |
| **APCu Object Cache** | Safe local fallback | Good for single-server sites when Redis is unavailable. | APCu is local to the PHP runtime and is cleared on PHP-FPM restart. If APCu writes fail or memory is full, UltraCache falls back safely to runtime-only behavior. |
| **Disk Object Cache** | Advanced/debug only | Use only when explicitly testing object-cache behavior. | Not recommended for production because it can create many small files and increase filesystem I/O. It is not used automatically as a fallback. |

## Current build

- Version: `2.56.07`
- Build type: CSS-scope-aware warm-up buttons and behavior
- Runtime focus: dashboard warm-up orchestration only; no extra frontend JS
- Default behavior: HTML-only warm actions remain separate; CSS warm actions follow the selected CSS Bundling Scope.

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

## Changelog highlights

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
