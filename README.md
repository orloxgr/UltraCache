# UltraCache

UltraCache is a production-oriented WordPress performance plugin focused on page-cache delivery, cache warming, object caching, media conversion, Redis-backed object caching, Varnish-aware purge workflows, and practical operator tooling.

It is built for sites that want a managed page-cache drop-in, optional Redis-backed object cache, optional Varnish integration, image format conversion, and a diagnostics-heavy admin experience without relying on external build tooling.

---

## Current repository build

- version: `2.54.136`
- latest pass: object-cache flush now distinguishes stale residual entries from entries recreated after flush by live runtime activity, and reports clearer flush semantics
- sixth pass fixes: removed hard page reloads after Varnish Test and Flush Varnish All; the dashboard now stays on the page and refreshes through AJAX only
- fifth pass fixes: reduced `@` suppression in non-critical helper paths and added safer internal debug logging for font CSS reads and Varnish admin socket connects
- third pass fixes: redacted secrets from `wp ultracache status` settings/all output, added stricter local-site URL validation for single-URL CLI actions, and tightened REST validation for settings enums and URL/scope inputs
- first pass fixes: removed auto-opening support modal, redacted secrets from WP-CLI settings output, added textdomain loading, and changed Local Google Fonts Optimization to opt-in by default for fresh installs
- second pass fixes: tightened REST schema coverage for settings/Redis/cron inputs and improved support modal accessibility metadata

---

## What UltraCache WP does

UltraCache includes:

- full-page HTML caching through a managed `advanced-cache.php` drop-in
- optional object caching through a managed `object-cache.php` drop-in
- cache warming for local URLs and front-page assets
- stale-while-revalidate page delivery
- optional Gzip and Brotli sidecar generation
- AVIF generation with WebP fallback support
- front-page CSS bundling and font optimization helpers
- WooCommerce-aware cache bypassing
- Varnish integration for purge workflows
- scheduled cache cleanup and cron warm-up queues
- admin diagnostics, cache inspection, and analytics
- REST endpoints used by the admin UI
- WP-CLI commands for operators

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

- defer JS
- delay third-party JS
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
- front-page CSS scan + bundle generation

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
