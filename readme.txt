=== UltraCache ===
Contributors: orloxgr
Tags: cache, performance, redis, varnish, webp, avif, apcu
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.56.202
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

UltraCache helps WordPress sites serve pages faster with full-page cache, object cache support, cache warm-up, media optimization, CSS/font optimization, Varnish-aware purge tools, and clear diagnostics.

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
`wp ultracache media status --media-format=best --format=json`
`wp ultracache media process --media-format=best --limit=5 --time-budget=20 --format=json`
`wp ultracache google_fonts_rebuild --clear`
`wp ultracache flush_object_cache`
`wp ultracache store_profile show --format=json`

== Frequently Asked Questions ==

= What should I enable first? =

Enable Page Cache first. Save settings, purge all cache, warm the homepage/menu cache, then check the homepage twice as a logged-out visitor.

= Does UltraCache support Varnish? =

Yes. Varnish support is optional. Use local/private endpoints only. Do not expose the Varnish admin port publicly.

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

Release notes are maintained in `changelog.txt`.

== Upgrade Notice ==

= 2.56.150 =
Generic Font Display Optimization using the existing font-display switch. See `changelog.txt` for release notes.
