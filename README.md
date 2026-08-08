# UltraCache

UltraCache combines WordPress page caching, object-cache integration, AVIF/WebP media rewrite, frontend optimization, warm-up tools, Varnish/LiteSpeed helpers, and diagnostics in one administrator-controlled plugin.

Version: `2.59.13.15`
Requires WordPress: `6.9` or newer  
Requires PHP: `8.1` or newer  
License: GPL-2.0-or-later

Version 2.59.13.15 makes Strong lifecycle findings emitter-timing-aware so DOM-ready, immediate, and deferred callback emissions are treated differently, while callback/unknown races are suppressed. Version 2.59.13.14 removes the obsolete 80-file ceiling from the resumable HTML JavaScript dependency analyzer so every prepared local JavaScript candidate is processed before correlation. Version 2.59.13.13 fixes resumable JavaScript evidence-to-script identity mapping and makes exact safeguard actions write exactly the displayed Strong Suggestion target. Version 2.59.13.12 adds Help guidance for JavaScript functionality failures that produce no Console errors, pointing administrators to Analyze HTML JS Dependencies and cautioning against blindly applying every finding. Version 2.59.13.11 fixes the resumable Analyze HTML JS Dependencies settings-integrity type error and surfaces its real batch/cache progress through the shared Warm/Media progress popup. Version 2.59.13.10 hardens Strong Suggestions so only deterministic Delay/Defer ordering conflicts are promoted, merges independent evidence for the same target into one finding, suppresses same-script self-correlation, and keeps uncertain async races out of the focused result stream. Version 2.59.13.09 adds phase-aware progress, automatic dashboard-refresh resume, and JavaScript-settings integrity checks to the resumable Analyze HTML JS Dependencies workflow. Version 2.59.13.08 consolidated that scan around one compact per-script evidence registry, so each local JavaScript file is read or cache-resolved once per scan and final correlation reuses that same evidence. Version 2.59.13.07 added persistent per-file lifecycle-analysis caching keyed by local file freshness so unchanged JavaScript reuses extracted evidence across scans. Version 2.59.13.06 makes Analyze HTML JS Dependencies resumable and batch-bounded so local JavaScript lifecycle inspection is split across short persisted iterations instead of one monolithic request. Version 2.59.13.05 added focused Strong Suggestions and generic event-dispatch wrapper analysis for silent Delay/Defer initialization races. Version 2.59.13.04 updated the Donate link and Warm Cache installation guidance. Version 2.59.13.03 added the selected page's real WordPress script dependency graph and local JavaScript lifecycle listener/emitter analysis.

Version 2.59.12.98 defers Elementor generated-file invalidation reconciliation until the end of the request, so repeated clears coalesce after the final clear before one canonical Flush All and the existing configured warm behavior run; it also documents the eight intentional versioned custom-table index repairs with narrowly scoped PHPCS SchemaChange ignores. Version 2.59.12.97 invalidates the canonical UltraCache page-cache layers when active Elementor clears its public generated-file cache, preventing cached HTML from referencing removed Elementor CSS files, and removes the obsolete Elementor Element Cache FAQ guidance. Version 2.59.12.96 replaces dynamic media-queue and replacement-blocker IN-clause SQL construction with bounded fixed-size prepared batches, preserving bulk behavior while making every identifier and value placeholder statically verifiable. Version 2.59.12.95 aligns the runtime version constant with the plugin header so updated admin assets receive a new cache-busting URL. Version 2.59.12.94 corrected the setup popup guidance for automatic warm-up options. Version 2.59.12.93 added optional first-visit background warming for uncached public URLs. The first cold visitor STORE queues the URL into the existing shared warm pipeline, coalesces with on-visit CSS bundle work, obeys Background warm pages per minute, and completes the same enabled external-cache stages as full-site warming.

Version 2.59.12.86 uses one canonical Theme CSS validation status in the resumable Prepare path, so successfully checksum-validated files are recorded as validated and are not falsely reported as disappeared.

Version 2.59.12.79 makes Media Library Replacement Prepare, Do, Verify, and Delete Originals durable across separate administrator sessions and days. Prepare validity now depends only on persisted plan evidence and fingerprints; database and Theme CSS destructive confirmations are issued and consumed just in time inside the authenticated Do phase, then replaced by durable plan-fingerprint authorization for resumable chunks.

Version 2.59.12.78 keeps Media Library Replacement blocker actions attached to the authoritative active job, clears every derived blocker and preview state after Restart Replacement Plan, replaces decisions on initial modal load, and rejects cross-job blocker responses instead of presenting an empty Decide Blockers modal.

Version 2.59.12.77 prevents Media Library Replacement database discovery from entering unbounded PCRE work on large inline data-image payloads. The shared reference extractor now performs one deterministic bounded byte scan, ignores non-replaceable data URIs, preserves exact stored fragments for Apply and Verify, and retains existing HTML, CSS, JSON, escaped-slash, query-string, and srcset reference coverage.

Version 2.59.12.32 removes transient-backed frontend compression, loopback SSL, and object-cache support diagnostics. Compression results and environment support use fingerprinted revisioned state, while short-lived signed compression challenges use atomic database replay claims.

Version 2.59.12.31 removes transient-based Media, LCP, LiteSpeed, and Google Fonts coordination: cleanup cooldowns, queue deduplication, maintenance throttling, replay protection, and retry guards now use the existing atomic UltraCache lock table.

Version 2.59.12.26 corrects Batch BAN behavior verification by matching each post-BAN refill against the exact generation artifact written by UltraCache, so a verified server capability activates the existing production batch path while exact-per-URL fallback remains available.

Version 2.59.12.16 separates authenticated Varnish control connectivity from exact invalidation behavior in diagnostics, reports generic Admin VCL setups without contradictory connection errors, and displays bounded per-endpoint proof expiry while retaining the fail-closed runtime planner.

Version 2.59.12.15 requires current isolated-canary proof before Admin BAN, batch BAN, HTML-only flush, or entire-host flush can enter the runtime planner; failed tests and runtime operations now remove stale effective capabilities instead of trusting the admin transport contract.

Version 2.59.12.14 discards disposable warm-up runtime when upgrading from the public SVN baseline or private development schemas, removes legacy warm-row consolidation SQL, and serializes the shared lock-table schema upgrade before concurrent requests can run dbDelta.

Version 2.59.12.02 treats color-profile policy outcomes as semantic media skips, continues AVIF-with-WebP work to the WebP fallback, and adds a default-off option to ignore color-profile preservation for generated variants while leaving original images unchanged.

Version 2.59.12.01 restores native URL parsing inside the early advanced-cache drop-in so WordPress can bootstrap before wp_parse_url() becomes available, while retaining the wp-login.php frontend-engine exclusion.

Version 2.59.11.98 excludes the development test suite from the installable production package while keeping the complete tests in the development source tree.

Version 2.59.11.97 aligns the Per-endpoint proofs and Recent operation results with the horizontal label/value layout used by the upper Varnish diagnostics cards.

Version 2.59.11.82 adds query-string caching guidance to the Help popup without changing settings or runtime behavior.

Version 2.59.10.98 enforces that shared background URL budget once per real minute across overlapping cron invocations.

## Main features

- Anonymous public-page HTML cache with explicit bypass rules and optional Apache Static HTML Delivery, mutually exclusive with native LiteSpeed HTML Cache.
- Optional automatic page-cache and frontend-asset invalidation after successful WordPress core, active plugin, and active parent/child theme updates, without flushing object cache or optimized images.
- Managed `advanced-cache.php` and `object-cache.php` WordPress drop-ins.
- Redis, APCu, SQLite, runtime-only, and advanced disk object-cache backends.
- AVIF/WebP generation and frontend media URL rewriting with primary/fallback output policies and a shared compression level.
- Batch, upload, and background-queue media processing, sample conversion comparisons, and opt-in regeneration of existing optimized images.
- Resumable Media Library Replacement for attachment originals and generated sizes, including a Prepare → Decide Blockers → Apply workflow, grouped blocker decisions, verified metadata, database-reference, and Theme CSS updates.
- CSS bundling, async CSS, Google Fonts localization, and font CSS optimization.
- JavaScript defer/delay with editable exclusion controls.
- Strict viewport-based lazy loading for eligible third-party iframes, with automatic critical payment, authentication, CAPTCHA, and checkout/account exclusions.
- LCP priority with exact manual CSS selectors, SVG image and video/poster support, optional browser-based frontend discovery, persistent per-page and per-viewport learning, targeted page-cache refresh through the existing warm queue, lazy loading, and optional CLS dimensions.
- Homepage, menu, selected-URL, and full-site warm-up with per-page HTML, CSS, exact external-cache invalidation, and orig/WebP/AVIF refill work shared by dashboard, cron, CLI, and warm-after-flush jobs.
- Canonical affected-URL rebuilding for content changes, covering the post permalink, site front, blog/CPT/shop archives, affected pagination pages, public taxonomy archives, author/date archives, and feeds. Purge and warm share one normalized deduplicated plan; feeds remain purge-only, while cacheable pages run through queued HTML-variant, configured CSS-bundle, and optional Varnish and LiteSpeed invalidate/refill stages.
- Optional native LiteSpeed HTML caching, mutually exclusive with Apache Static HTML Delivery, with managed lookup and conservative bypass rules, Fresh TTL response control, isolated orig/WebP/AVIF cache keys, signed site-tag and exact-URL invalidation, opt-in stale exact-tag regeneration, Fresh TTL-derived refresh ahead through the shared warm queue, targeted affected-page refill, shared site-warm population, a per-bucket MISS/HIT behavior test, bounded production counters, and recent operation history.
- Optional administrator-configured Varnish integration with scheme-preserving HTTP/HTTPS endpoints, isolated generation-canary verification for managed HTTP exact-URL invalidation, candidate-only endpoint discovery, behavior-gated runtime invalidation, site-wide scopes only when native admin BAN or a separate topology proof supports them, a bounded persistent invalidation queue, unified resumable page-warm pipeline, Fresh TTL-derived Varnish lifetime and stale refresh controls, site-warm Varnish prewarm, HTTP soft-purge/SWR-gated refresh ahead, integrated refill inside warm-after-flush page processing, compact production outcome/strategy metrics, bounded parent-cache performance snapshots, and live queue counters.
- Automatic capability-gated public and private ESI fragments with registered renderers, signed same-origin fragment URLs, bounded scalar context, per-fragment public TTL, cookie-scoped private session transport, inline fallback HTML, cache-file ESI sidecars, end-to-end Varnish capability verification, an automatic WooCommerce classic mini-cart adapter, and passive sampled render-cost telemetry with rolling 24-hour visibility.
- Dashboard and WP-CLI diagnostics, including persistent browser-observed LCP mapping, warm-refresh inspection, and native LiteSpeed transport, behavior-test, hard/stale purge, refresh-ahead, refill, and cache-signal telemetry.

## Storage and WordPress drop-ins

UltraCache stores generated assets and runtime cache data below the resolved WordPress uploads directory in `uploads/ultracache/`. Browser-observed LCP mappings are stored separately in the WordPress database table `{prefix}ultracache_lcp_observations`. LCP Frontend Discovery can run for public visitors or only administrators for a selected duration. The first valid observation becomes active immediately, while a rolling two-of-three confirmation locks each page and viewport and stops further reporting for that scope. URLs with query parameters are excluded. Exact manual selectors take precedence, confirmed mappings remain active until explicitly relearned or forgotten, one winner per page and viewport can emit a preload, and page-specific refresh jobs remain in the existing cron warm queue table.

WordPress requires `advanced-cache.php` and `object-cache.php` directly in the active content directory. UltraCache manages those files there only when their related features are enabled.

## Page cache

The page cache targets anonymous public requests. Logged-in users, administration paths, cart/checkout/account flows, unsafe cookies, and configured exclusions are bypassed. Query-string variants require an explicit allowlist containing every query key; an empty allowlist bypasses query-string caching.

The Cache Engine can purge page HTML and invalidate generated frontend asset maps after successful WordPress core updates, active plugin updates, and updates to the active child or parent theme. Bulk plugin/theme operations trigger at most one purge, inactive plugin/theme updates are ignored, and this path does not flush object cache or optimized-media storage.

## Public and private ESI fragments

The bundled Control Web Panel (CWP) v2 template is a standalone WordPress/WooCommerce configuration: it uses exact domain matching, a local-direct exact PURGE receiver, canonical AVIF/WebP/original HTML Accept buckets, internal object host/URL metadata, origin-controlled grace from the Automation stale-while-revalidate value, and an independent keep window. WooCommerce shared-parent reuse requires both the host-only `ultracache_esi_optin=1` session marker and `X-UltraCache-ESI-Shared-Parent: 1`; without both signals, cart/session requests remain in normal PASS mode. The private carrier includes only the built-in allowlisted cookies and is restored only on signed UltraCache private fragment endpoints. Frontend ESI capability probes use an independent 20-second timeout.

The bundled CWP template contains no embedded credential and require no token replacement or enable switch. Local-direct exact PURGE is available through the per-domain vhost, while installations using UltraCache Varnish admin mode keep authentication in the existing Varnish admin configuration and gain exact, batch, HTML-only, and entire-host BAN without changing the template.


Public fragments keep deterministic signed URLs, independent TTLs, exact invalidation, and shared fragment caching. Private fragments have no shared TTL, are always returned with `private, no-store`, and receive only cookies explicitly allowed by their definition. Private fallbacks must be static, non-personalized HTML and are rendered with an empty cookie jar and anonymous WordPress user so visitor state cannot enter the cached parent.

Register a public fragment during plugin or theme initialization:

```php
add_action('init', function () {
    ultracache_register_esi_fragment('latest-news', array(
        'scope'                   => 'public',
        'ttl'                     => 300,
        'max_render_ms'           => 2000,
        'context_keys'            => array('category_id'),
        'max_context_bytes'       => 1024,
        'max_context_value_bytes' => 200,
        'renderer'                => function (array $context) {
            return '<div>Latest news for category ' . (int) $context['category_id'] . '</div>';
        },
        'fallback'                => function (array $context) {
            return '<div>Latest news for category ' . (int) $context['category_id'] . '</div>';
        },
    ));
});
```

Register a private fragment with a strict cookie allowlist and a generic, non-personalized fallback. A renderer that relies on WordPress login state must explicitly transport the logged-in cookie prefix:

```php
add_action('init', function () {
    ultracache_register_esi_fragment('account-indicator', array(
        'scope'           => 'private',
        'max_render_ms'   => 2000,
        'cookie_prefixes' => array('wordpress_logged_in_'),
        'renderer'        => function () {
            return is_user_logged_in()
                ? '<a href="/my-account/">My account</a>'
                : '<a href="/wp-login.php">Sign in</a>';
        },
        'fallback'        => '<a href="/wp-login.php">Sign in</a>',
    ));
});
```

Fragments should normally be registered on `init`, before WordPress decides whether the template needs full-output buffering. An integration that intentionally registers fragments during template rendering must opt in early to the legacy compatibility buffer:

```php
add_filter('ultracache_esi_force_template_buffer_for_late_registration', '__return_true');
```

Without that explicit opt-in, a fragment registered after an unbuffered template decision renders its safe inline fallback for that request.

One parent page emits at most 32 live includes and 64 KiB of generated ESI directives by default; the filters `ultracache_esi_max_parent_fragments` and `ultracache_esi_max_parent_directive_bytes` may lower or raise those values within the hard bounds. `max_render_ms` is a soft post-render budget: PHP callbacks cannot be preempted, but output that exceeds the budget is rejected and contained through the registered fallback. Fragment renderer/filter output cannot introduce nested ESI directives.

The Varnish transport policy and the WordPress registration must agree on which cookies may be removed from the shared parent and forwarded to private subrequests. After adding the same cookie to the copyable VCL rules shown in the Varnish card, declare that transport policy in WordPress:

```php
add_filter('ultracache_esi_private_transport_cookie_prefixes', function (array $prefixes) {
    $prefixes[] = 'wordpress_logged_in_';
    return $prefixes;
});
```

Exact-name policies may be declared with `ultracache_esi_private_transport_cookie_names`. A private definition remains on its anonymous inline fallback unless every requested cookie name/prefix is covered by the declared transport policy. This prevents a verified probe cookie from incorrectly enabling a fragment whose real session cookie was never added to VCL.

The canonical request-opt-in VCL in Help → FAQ preserves WooCommerce cart/session cookies on cache misses and permits shared-parent lookup only when the browser carries the host-only `ultracache_esi_optin=1` session marker. UltraCache emits that marker only on verified classic mini-cart adapter pages and approves only parents whose ESI metadata contains that exact fragment. Without both signals, WooCommerce requests remain in normal PASS mode. Custom private cookies must still be declared through `ultracache_esi_private_transport_cookie_names` or `ultracache_esi_private_transport_cookie_prefixes` and added to the administrator-managed VCL allowlist; a private definition stays on fallback unless its complete allowlist is covered.

Render either scope in a template:

```php
echo ultracache_get_esi_fragment_markup(
    'latest-news',
    array('category_id' => 12)
);

echo ultracache_get_esi_fragment_markup('account-indicator');
```

Render the WooCommerce **classic** mini-cart adapter with either template helper or shortcode:

```php
echo ultracache_get_woocommerce_esi_mini_cart_markup();

ultracache_render_woocommerce_esi_mini_cart(array(
    'class' => 'site-header-mini-cart',
));
```

```text
[ultracache_esi_mini_cart class="site-header-mini-cart"]
```

The adapter registers `woocommerce_items_in_cart`, `woocommerce_cart_hash`, and the `wp_woocommerce_session_` cookie prefix as its complete private scope. The Varnish card's copyable rules remove those cookies from the shared parent and restore them only for the private fragment. Test Varnish verifies the three-cookie transport before the adapter can leave fallback mode.

The classic adapter keeps WooCommerce's standard `div.widget_shopping_cart_content` selector and ensures `wc-cart-fragments` remains available on pages where the adapter is rendered, so native add/remove-cart events can replace the mini-cart. UltraCache's optional empty-cart suppression and cart-fragment delay are disabled only for those adapter pages. The block-based Mini-Cart is a separate Store API/Interactivity integration and is not converted by this adapter.

Inspect or invalidate an exact public context without purging any parent page:

```php
$definition = ultracache_get_esi_fragment_definition('latest-news');
$context_hash = ultracache_get_esi_fragment_context_hash(
    'latest-news',
    array('category_id' => 12)
);
$result = ultracache_purge_esi_fragment(
    'latest-news',
    array('category_id' => 12)
);
```

Private fragments are never stored in shared cache and therefore reject the public-fragment purge API. Context remains URL-visible and must never contain secrets, authentication material, or visitor identity. Cached ESI parents use atomic version-4 `.esi` sidecars with public/private reference counts, the standard `Surrogate-Control: content="ESI/1.0"` contract, identity/gzip origin representations, and no Apache Static HTML alias. Logged-in whole-page cache bypass and the Cart, Checkout, and My Account exclusions remain unchanged.

## Object cache

The recommended object-cache order is Redis, APCu, SQLite for persistent local storage on single-server sites, runtime-only fallback, and Disk only for advanced or diagnostic use. The dashboard reports the configured backend, the backend actually in use, and any active fallback. When SQLite is selected, its main database-file limit is configurable from 32 MB to 2048 MB and defaults to 256 MB.

## Media Rewrite and conversion

Media Rewrite changes frontend image URLs to generated AVIF/WebP variants when those files exist and the selected policy permits them. Image Rewrite Format selects the primary rewrite format, Image Rewrite Fallback Format controls the compatible secondary output, Upload image format independently selects the real file format used by Convert new uploads, and Image replacement format selects the destructive Media Library Replacement target. The shared Image compression level applies across media optimization, upload conversion, and Media Library Replacement. The Original file fallback preserves the attachment's current file format, which may be JPEG, PNG, AVIF, or WebP after upload conversion or Media Library Replacement. AVIF sources enter the existing WebP pipeline only when the same Imagick or GD engine passes the bundled AVIF decode and WebP encode self-test.

WebP is the default for fresh installations. AVIF is enabled only after UltraCache validates bundled opaque and transparent encode/decode regression tests. Frontend requests remain lookup-only; encoding runs through upload processing, batch conversion, or background queue workers. The dashboard also includes sample WebP/AVIF comparisons and opt-in regeneration of existing optimized images with the current quality setting.

## Media Library Replacement

Media Library Replacement promotes verified UltraCache-generated AVIF/WebP rewrite files into the WordPress Media Library. It covers attachment originals and registered intermediate sizes, updates attachment metadata, and can replace matched media references in current-install database tables and active parent/child theme CSS files. Prepare publishes each destination through a validated same-directory temporary file and atomic commit. Different existing AVIF/WebP destinations stop the plan by default; the explicit Overwrite with verified backup policy preserves the previous bytes in the existing replacement registry for rollback until Delete Originals completes. Same-job mapping conflicts that require different bytes at one destination remain blocked and cannot be overridden.

## CSS, JavaScript, and fonts

The plugin includes CSS bundling, async CSS controls, JavaScript defer/delay, Google Fonts localization, self-hosted font CSS optimization, delayed icon-font loading, and optional runtime font CSS rewriting. Features that can affect layout or timing have visible controls and exclusions.

## Installation

1. Upload and activate UltraCache.
2. Open the dashboard from the WordPress admin menu or admin bar.
3. Select the **Aggressive** profile and save the settings.
4. Run the **HTML Compression** check under Cache Engine.
5. In **Warm Cache**, select the main frontend menu and the required depth. First-level menu URLs suit most websites.
6. Select the required **Full-site warm-up sources**. Homepage / blog index, Selected menu URLs, Pages, Posts, and Categories cover most sites.
7. Under **Media settings & Media Library replacement**, enable **Convert new uploads**, set **Maximum upload image side** to `1920` unless larger source images are required, and choose **Upload image format** independently from **Image Rewrite Format** and **Image Rewrite Fallback Format**.
8. Select **Compact** under Image compression level. When using AVIF, run **Image conversion test** and then **Check test**.
9. In **Fonts Optimization**, enable **Local Google Fonts Optimization**, **Bundle Generated Font-Mix CSS**, and **Delay icon fonts**.
10. For WooCommerce, enable **Suppress empty-cart execution**. For MailerLite, enable **Lazy MailerLite nonce refresh**.
11. Confirm the detected Object Cache backend and configure Varnish when present.
12. In **Automation & Scheduling**, enable **Warm full site after Scheduled Cleanup** when scheduled cleanup should refill the selected full-site URLs.
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
