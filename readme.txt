=== UltraCache ===
Contributors: orloxgr
Tags: cache, performance, redis, varnish, webp, apcu
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.56.27
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
