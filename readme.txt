=== UltraCache ===
Contributors: orloxgr
Tags: cache, performance, redis, varnish, webp
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.54.097
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Static HTML page caching, cache warming, Redis object cache, Varnish-aware purging, AVIF/WebP optimization, and operator-friendly diagnostics.

== Description ==

UltraCache is a production-oriented performance plugin for WordPress sites that want practical caching controls, fast warm-up tools, Redis-backed object caching, optional Varnish integration, media conversion, and a diagnostics-heavy admin dashboard.

Main capabilities include:

* Full-page HTML caching through a managed advanced-cache drop-in
* Stale-while-revalidate cache delivery
* Frontpage and full-site warm-up tools
* Menu-only warm-up actions for fast wins
* Optional Redis object cache drop-in management
* Optional Varnish-aware purge workflows
* AVIF generation with WebP fallback
* Frontpage CSS bundle generation
* Gzip and Brotli sidecars when supported
* WooCommerce-safe bypass logic
* WP-CLI support for operators

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate **UltraCache** through the Plugins screen in WordPress.
3. Open the **UltraCache** dashboard in wp-admin.
4. Review Diagnostics before enabling caching features.
5. Enable page cache first, then test purge and warm-up flows.
6. Enable Redis, media conversion, and advanced optimizations only after validation.

== Frequently Asked Questions ==

= What should I press first for fast results? =

Use **Warm Up Menu HTML Cache + Frontpage CSS** when you want a fast, low-risk warm-up focused on the URLs users are most likely to visit first.

= When should I use Full Site warm-up? =

Use **Warm Up Full Site HTML Cache** or **Warm Up Full Site HTML Cache + Frontpage CSS** after larger content updates or when you want to rebuild the full public cache scope.

= Does it support Redis and Varnish? =

Yes. UltraCache can manage Redis-backed object caching and includes Varnish-aware purge workflows when your server stack supports them.

== Changelog ==

= 2.54.097 =
* Follow-up plugin-check cleanup for the query fallback helper.
* Repository follow-up cleanup for WordPress.org checks.
* Updated readme metadata and stable tag consistency.
* Reduced plugin-check findings around request sanitization and filesystem wrappers.
* Kept Varnish admin transport while documenting the remaining protocol-specific exception area.

= 2.54.087 =
* WordPress.org cleanup pass.
* Added repository-ready readme.txt metadata.
* Reduced direct filesystem fallbacks in the main runtime wrappers.
* Improved sanitization helpers and naming consistency.
* Cleaned several debug/report issues in the repository build.

== Upgrade Notice ==

= 2.54.097 =
Repository compatibility cleanup and metadata fixes.

= 2.54.087 =
Repository cleanup and compatibility improvements for WordPress.org submission.

= 2.54.095 =
* Fixed noisy GD AVIF capability warnings when the encoder probe is unavailable while Imagick AVIF support still exists.
* Remapped legacy Varnish 6081/6082 endpoints to the site frontend HTTP origin and updated Varnish HTTP wording/UI defaults.
