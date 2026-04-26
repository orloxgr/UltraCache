=== UltraCache ===
Contributors: orloxgr
Tags: cache, performance, redis, varnish, webp, apcu
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.56.07
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Static HTML page caching, cache warming, Redis/APCu object caching, Varnish-aware purging, AVIF/WebP optimization, CSS bundle generation, and operator-friendly diagnostics.

== Description ==

UltraCache is a production-oriented performance plugin for WordPress sites that want practical caching controls, fast warm-up tools, Redis/APCu object caching, optional Varnish integration, media conversion, CSS bundle generation, and a diagnostics-heavy admin dashboard.

= Varnish Support and object-cache compatibility =

* **Varnish Support:** optional. HTTP mode can target frontend BAN/PURGE endpoints. Admin mode should use localhost/private endpoints only, for example `127.0.0.1:6082`. Do not expose the Varnish admin port publicly.
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
* Homepage/shared/per-page CSS bundle generation
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

Do not hard-code theme-specific protections into the plugin. Add theme/custom script handles, filenames, or globals to **Defer / Delay Exclusions** manually when browser Console shows a dependency-order issue.

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
* Settings cleanup: deprecated local-build keys no longer leak into CLI/settings output.
* No tracking-query default changes were made.


= 2.56.00 =
* Expanded the HTML rewrite refactor across script/link asset cleanup and LCP image handling.
* Added parser-assisted script/link removal for asset cleanup and frontend authoring asset stripping, with conservative marker-based deletion and regex fallback.
* Improved LCP image candidate handling for srcset/source-style candidates and parser-backed priority attributes.
* Kept legacy regex fallbacks and HTML rewrite safety bailouts active for malformed edge cases.

= 2.55.98 =
* Refactored stylesheet/link rewrite paths to prefer WP_HTML_Tag_Processor where possible.
* Preserved legacy fallbacks and safe noscript stylesheet fallbacks.

= 2.55.97 =
* Added deeper parser-assisted preload duplicate checks and shared safe head injection helpers.

= 2.55.96 =
* Removed theme-specific built-in JS protections. Site/theme-specific issues should be solved with manual exclusions.

= 2.55.90 =
* Removed border styling from diagnostic status pills and value rows.
* Renamed CSS Bundle Diagnostics to CSS Bundle Summary and added CSS Bundle Summary to Activity Summary.

= 2.55.88 =
* Cleaned Media Optimization naming so mediaOptimizationEnabled is the master switch and output mode controls Auto / AVIF / WebP behavior.

= 2.55.84 =
* Added Disk Object Cache and Varnish Admin mode warnings.
* Added analytics backend live probe for APCu/Redis.

= 2.55.81 =
* Changed analytics hits to APCu -> Redis -> disabled.
* Changed object cache strategy to Redis -> APCu -> runtime-only, with Disk only as explicit advanced/debug mode.

== Upgrade Notice ==


= 2.56.06 =
Scoped admin UI spacing fix. Removes the default WordPress left gutter only on the UltraCache dashboard.


= 2.56.05 =
LCP master-switch build. Adds Advanced Settings control for LCP Optimization and SR7/Revolution Slider hashed asset discovery for safer LCP Image Priority and LCP Boundary Defer.

= 2.56.02 =
WP-CLI cleanup build. Adds `wp ultracache purge --all` and removes deprecated legacy keys from CLI/settings output.
