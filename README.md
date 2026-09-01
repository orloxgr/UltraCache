# UltraCache

UltraCache combines WordPress page caching, object-cache integration, AVIF/WebP media rewrite, frontend optimization, warm-up tools, Varnish/LiteSpeed helpers, and diagnostics in one administrator-controlled plugin.

Version: `3.13.12`

Version 3.13.12 keeps Runtime Scanner preparation inside the shared warm pipeline but makes external-cache refill failures non-blocking for the existing `runtime_scan` foreground session. The page-cache warm remains required; Varnish/LiteSpeed refill failures are retained as exact warning diagnostics and browser measurement continues. Normal manual Warm / Flush+Warm refill semantics are unchanged.

Version 3.13.11 makes the Setup Wizard a thin orchestrator over the shared Runtime Site Scan. The Wizard no longer throws its own generic JavaScript verification failure, no longer interprets failed/unresolved scan targets independently, and never persists JavaScript as a failure-resume step. Shared scanner warnings and exact target reasons flow through to Verify while setup continues; legacy persisted JavaScript failures advance to Verify instead of looping back into the JavaScript step.

Version 3.13.10 completes consent-family ownership handoff for Real Cookie Banner structural application/consent families. When an inline WordPress companion is held by an application/consent contract, its external consumer now inherits the same consent-required/by/id contract and is restored as consent-original-src-_ instead of executing natively before its provider. The fix is structural and family-based: no Mailchimp/vendor hardcode, hidden exclusion, visible-list migration, scanner-policy change, or consent-controller bypass is added.

Version 3.13.08 keeps the 3.13.07 raw-source single-transform warm contract and fixes its authenticated-caller context leak. Warm finalization now explicitly treats raw-v1 source HTML as anonymous public frontend output even when warm_url() is executing inside a logged-in admin/REST request, so the final dependency-family normalization, inline registry and other frontend rewrites are not skipped. The override is scoped only around public cache finalization and the original caller context is restored immediately afterwards.

Version 3.13.05 preserves the browser completion contract of runtime-created delayed scripts. UltraCache now revives the same dynamic script DOM object instead of replacing it, so creator-owned onload/onerror handlers, addEventListener callbacks, Promise-based resource loaders, and retained element references survive DEFER/DELAY release. Static HTML script placeholders keep the existing replacement path; no vendor exclusion, JavaScript default, or consent compatibility behavior changes.

Version 3.13.04 hardens scanner-first DELAY correctness without adding hidden exclusions: delayed WordPress families now carry their real dependency graph into the browser loader, provider/consumer groups execute in stable dependency order while staying DELAY, interaction release keeps consumers held when an ineligible dependency remains delayed, and Runtime Scan can use observed delayed execution sequence when resolving providers. Real Cookie Banner, Complianz, and the three visible JavaScript policy lists remain unchanged.

Version 3.13.01 moves the existing Real Cookie Banner Compatibility switch to Advanced Settings and lets the Setup Wizard silently enable it when an active Real Cookie Banner Lite/Pro installation is detected. If the banner is not detected, the wizard preserves the existing switch value. No JavaScript list, runtime-routing, or compatibility behavior changes.

Version 3.12.38 adds an explicit Real Cookie Banner Compatibility layer. It preserves only Real Cookie Banner controller/blocker infrastructure outside UltraCache scheduling, keeps consent-released service scripts inside the normal NATIVE / DEFER / DELAY firewall, resets isolated RCB consent storage before every Runtime Scan lifecycle, and invalidates UltraCache after RCB configuration/revision changes. The switch is OFF by default and never rewrites visible JavaScript lists.

Version 3.12.36 introduces the Unified Inline Registry. Non-NATIVE inline JavaScript is collected into one inert per-page manifest with occurrence-based identity. DEFER occurrences use one shared cached dispatcher at their original DOM positions; DELAY occurrences remain inert placeholders that resolve their source from the same registry. NATIVE and parser-sensitive inline scripts remain browser-owned.

Version 3.12.35 adds unified WordPress script-family classification. Registered and runtime-created handles that own executable inline before/after/data/translations companions are classified as one compatibility family before broad Delay All/non-critical rules, using DEFER rather than NATIVE when the family has no explicit visible third-party Delay match. The 3.12.34 Runtime Script Classification Firewall remains unchanged.

Version 3.12.34 adds a Runtime Script Classification Firewall. Runtime-created executable scripts intercepted by UltraCache now enter the same NATIVE / DEFER / DELAY policy before execution; runtime DEFER and DELAY are controlled by the UltraCache executor, while Runtime Scan verifies zero pending/escaped runtime classifications.

Version 3.08.15 adds a narrow, source-proven jQuery-Bridget provider resolver for JavaScript Error Fixer. Dynamic registrations such as Flickity's `fn[name]` bridge can now resolve the real delayed provider behind `.flickity is not a function` without changing the established direct jQuery.fn, alias, UMD/factory, dependency, computed-global, lifecycle, cache, ESI, or warm-up fixers.

Version 3.07.08 adds a narrow lifecycle-aware delayed-script ordering repair on top of 3.07.07. When an inline delayed configuration handle publishes a global and ready event, and one of its declared local runtime dependencies is source-proven to support that pre-existing global while also using a post-load missing-data fallback, UltraCache marks only that inline/runtime edge and executes the configuration immediately before the delayed runtime. The Delay classifier and all unrelated dependency ordering remain unchanged.
Version 3.06.19 reorganizes the JavaScript safeguard UI so all four execution lists are edited together above Browser Scanner and Console Error Handler, provides one Save Lists action for the entire group, and renames the safe third-party labels to Delay third-party JS / Delay third-party JS Patterns without changing the underlying setting keys.

Version 3.06.18 extends the Browser Runtime Scan repair budget to ten real cycles and adds performance-first strategy escalation for deterministic provider/consumer ordering failures: keep a proven delayed provider and deferred consumer together in Delay first, then use Defer Instead, and only finally Do Not Defer or Delay if the same error persists. Version 3.06.17's computed-global and runtime-settling fixes remain the baseline.

Version 3.06.08 adds a separate native LiteSpeed ESI subsystem for LiteSpeed Enterprise/Web ADC, including signed same-origin fragment endpoints, native `esi=on` parent rendering, isolated LiteSpeed ESI tokens, and a WooCommerce classic mini-cart fragment that stays dynamic while the surrounding page remains publicly cacheable. OpenLiteSpeed is excluded because it does not support ESI, and the existing Varnish ESI/VCL contract remains independently gated.

Version 3.06.07 isolates the existing fragment-processing implementation as Varnish ESI, keeps activation bound to the existing end-to-end VCL capability proofs, and moves the registry, rendering, endpoint, lifecycle, and WooCommerce mini-cart adapter behind explicit Varnish ownership while preserving the established VCL wire protocol.

Version 3.06.06 hardens the WooCommerce routing contract with first-class query-routed page rules and current endpoint query vars, preserves custom endpoint slugs, handles plain permalinks without broad fail-closed routing, and prevents broken page assignments from falling back to the public homepage.

Version 3.06.05 introduces one canonical public HTML cache eligibility contract with `CACHEABLE`, `BYPASS`, and `PRIVATE` states. Runtime requests and warm/preload decisions now pass through the same decision object while the existing boolean bypass methods remain compatibility adapters, and URL inspection surfaces the canonical state directly.

Version 3.06.04 adds direct LiteSpeed redetect observability in the server card, showing the last anonymous detection request, resolved URL, response time, and relevant LiteSpeed/UltraCache response headers for troubleshooting.

Version 3.06.03 separates LiteSpeed origin detection from cache-behaviour verification. UltraCache now treats direct LiteSpeed/OpenLiteSpeed origin evidence as sufficient to enable the native integration, removes the HIT/MISS capability test from the dashboard and Setup Wizard, and keeps LiteSpeed plugin/CDN metadata separate from origin detection.

Version 3.06.01 introduced the internal Infinite Delayed JS fallback mode. As of 3.06.26 its visible label is Do not autostart JS, Scroll is no longer a release trigger, and the remaining automatic interaction set is Mouse move, Keyboard, Touch / pointer, and Click.

Version 3.05.04 finalizes the LiteSpeed simplification: Automation is the only warm/stale policy authority, retired LiteSpeed refresh-ahead/warm/refill/stale settings and scanner state are removed, and the LiteSpeed card is reduced to native-cache control, Flush All integration, capability actions, detection/transport status, stale exact-purge capability, counters, and recent operations. Varnish behavior is unchanged.

Version 3.04.53 makes WPML warm discovery deterministic across frontend, REST, and background execution: early contexts consume the last proven multilingual topology, live topology is observed only after WPML's supported post-`wp` lifecycle point, WPML object URLs use translated IDs plus route-only permalink conversion, and scheduled/full-site discovery preserves independent per-language cursors and URL ceilings.

Version 3.04.50 removes every non-profile border from the Multilingual dashboard section while preserving the bordered per-language Warm profile accordions and their right-aligned chevrons.

Version 3.04.48 simplifies the Multilingual dashboard presentation by removing non-profile borders and adding a right-side chevron to each per-language Warm profile accordion, while preserving all multilingual runtime and warm-policy behavior.

Version 3.04.46 fixes WPML public language-home topology resolution without hardcoded language paths. It accepts WPML language-switcher URLs only in neutral topology contexts when current-language home resolution falls back to the unchanged base, reuses already-proven persisted roots on normal frontend requests, and hides per-language warm summaries while topology is not ready.

Version 3.04.45 hardens Apache Static HTML Delivery activation and Varnish-aware capability verification. The Apache Static switch now owns verification, capability probes can generate their temporary static alias without persisting the setting, verified Varnish objects are invalidated before origin proof, and the Setup Wizard stays silent when Varnish is not detected.

Version 3.04.23 stabilizes LiteSpeed cache-tag identity across HTTP/HTTPS and multilingual request contexts. Site purge now uses LiteSpeed's native public-cache purge contract so stale objects created with older tag identities are invalidated, while exact URL tags remain language-path specific.

Version 3.04.22 hardens internal same-site control traffic against request-filtered URL schemes: LiteSpeed purge control now uses the configured WordPress site scheme while preserving explicit purge URL identity, and Media background-worker signing no longer depends on contextual home_url() values.

Version 3.01.10.3 fixes early-bootstrap WooCommerce routing resolution so WP-CLI/admin drop-in reconciliation can run before WordPress initializes its rewrite object without calling permalink APIs too early. The persisted dynamic WooCommerce contract is reused during that bootstrap window, while first-install/no-contract states fail closed until normal routing bootstrap is ready. Version 3.01.10.2 replaces hardcoded WooCommerce cart/checkout/account paths with one dynamic modern WooCommerce routing contract shared by the page-cache decision engine, advanced-cache drop-in, LiteSpeed/Apache server rules, Varnish and LiteSpeed refresh-ahead, speculation rules, iframe protection, and WooCommerce ESI. The bundled CWP Varnish template also stops assuming default WooCommerce route names and stores dynamic responses only after the UltraCache cacheability contract explicitly approves them. Routing changes are treated as a clean-slate cache boundary so a formerly public URL cannot survive as a cached object after becoming Cart, Checkout, or My Account.
Requires WordPress: `6.9` or newer  
Requires PHP: `8.1` or newer  
License: GPL-2.0-or-later

Version 3.00.06 makes both Runtime Scan captures load the exact same scan URL and rendering path. The scan temporarily disables only the normal JavaScript optimization switches for the first capture, leaves defer/delay safeguard lists untouched, flushes and warms, restores the saved switches, then flushes and warms the same URL again before differential comparison.

Version 3.00.01 introduces the WordPress-facing title **UltraCache - Cache and Speed Optimization** and promotes the plugin to the 3.00.01 release series while preserving its existing internal identity and runtime behavior.

Version 2.59.13.84 invalidates the PHP OPcache entry for wp-config.php after verified managed writes so newly saved Varnish/Redis secret constants are visible immediately to the next request, and exposes the runtime planner reason when a Varnish capability probe is blocked before transport. Version 2.59.13.83 restores the Varnish helper compatibility export required by the admin module contract, preventing Varnish/cache/dashboard bootstrap failures introduced in 2.59.13.81. Version 2.59.13.81 keeps the Varnish endpoint field synchronized with Purge mode when it still contains the previous mode's default endpoint, while preserving custom endpoints. Version 2.59.13.80 gives Redetect Varnish Capabilities a dedicated 20-second Varnish admin-socket timeout for diagnostic exact-invalidation probes without changing the normal production invalidation timeout. Version 2.59.13.79 makes Save Varnish Settings automatically redetect Varnish capabilities and persist the verified settings again, renames the manual test action to Redetect Varnish Capabilities, and hides the standalone Varnish performance button. Version 2.59.13.78 adds Query-string args whitelist population to the existing Setup Wizard configuration progress before font optimization, reusing the normal Populate detector and settings save path. Version 2.59.13.77 moves Browser Runtime Scan cycle progress from the inline JavaScript diagnostics panel into a dedicated live modal without changing the underlying scanner or repair flow. Version 2.59.13.76 enables LCP Frontend Discovery and Lazy load third-party iframes in the existing recommended Setup Wizard configuration. Version 2.59.13.75 hides Setup choices as soon as the Wizard advances from configuration into live setup progress. Version 2.59.13.74 widens the unified Setup Wizard review layout so Setup choices sits directly below its explanatory text and spans nearly the full modal width. Version 2.59.13.73 simplifies the unified Setup Wizard: the visible Website analysis summary is removed while detection remains internal, setup screens use a single Next action, and the warm-up selector now defaults to Homepage. Version 2.59.13.72 merges the fresh-install and repeatable optimal-configuration paths into one Setup Wizard, adds Object Cache and warm-up scope selectors backed by the existing subsystems, removes duplicate Optimal Configuration quick actions, and preserves manually maintained infrastructure and list settings. Version 2.59.13.71 fixes homepage-first foreground media conversion so the active dashboard manual-session token is forwarded into attachment processing, preventing the shared worker from rejecting its own exclusive queue ownership with HTTP 409 during Apply Optimal Settings or normal Start / Resume Conversion. Version 2.59.13.70 removes the direct PHP `is_writable()` fallback from setup object-cache backend planning and uses WordPress core `wp_is_writable()` directly, resolving the WordPress.WP.AlternativeFunctions.file_system_operations_is_writable compliance error without changing backend selection behavior. Version 2.59.13.69 makes AVIF/WebP Batch Conversion homepage-first: Start / Resume Conversion refreshes homepage media discovery under exclusive foreground ownership, converts homepage Media Library images plus local theme/plugin assets first, then continues through the normal full-library conversion. Apply Optimal Settings reuses the same foreground homepage phase but stops at the homepage checkpoint, does not start the remaining library in the background, and exposes a Skip image conversion action. Version 2.59.13.68 made Apply Optimal Settings and the First-Run Setup Wizard reuse the exact same six-scan self-healing Browser Runtime Scan cycle as the standalone diagnostic.

Version 2.59.13.58 separates wrong-type TypeErrors from genuine missing-global failures: Error Fixer now repairs the exact failing consumer strategy for `X.foo is not a function` and no longer treats self-referential state transforms as symbol providers.

Version 2.59.13.57 guarantees visible exact actions for every appendable JavaScript Error Fixer finding and downgrades filesystem-only symbol providers that are absent from the scanned page inventory to review-only evidence.

Version 2.59.13.56 makes unresolved runtime-source fallback findings actionable: when no concrete provider can be resolved, the exact owner-relative plugin/theme source is now explicitly recommended for Do Not Defer or Delay so the Error Fixer renders the append action. Version 2.59.13.55 makes jQuery-plugin Error Fixer findings strategy-aware: when Runtime Scan proves one concrete provider executes later than its direct consumer, only the provider is recommended with the least-invasive action selected from the scanned execution order; the consumer remains causal evidence instead of a duplicate appendable fix. Version 2.59.13.54 restores anonymous inline third-party pattern delay without reopening incidental prefix collisions: user-visible prefix markers ending in `-` or `_` now require a left identifier boundary, while URL/domain/code fragments and established simple-token matching retain their existing substring behavior. The dependency-preserving Do Not Defer or Delay closure introduced in 2.59.13.52 remains unchanged. Version 2.59.13.51 makes JavaScript Error Fixer actions follow the backend recommendation: confirmed console findings expose only the exact Defer Instead or Do Not Defer or Delay action selected from actual execution order, and bulk append buttons now operate only on findings for their own preferred target. Version 2.59.13.50 canonicalizes diagnostic WordPress script families so external, before/after, extra, and translation fragments inherit the same registry-backed handle/dependency metadata without inventing families from IDs alone. Version 2.59.13.49 makes WordPress inline companion errors parent-first: `*-js-after` errors resolve their owning enqueued script before dependency diagnosis, directly fix a non-blocking parent that can execute after its own inline block, and otherwise repair every proven late direct dependency of that exact blocking parent without reintroducing page-wide Analyzer findings. Version 2.59.13.48 adds causal consolidation to the restored JavaScript Error Fixer: it suppresses only downstream fallbacks proven to depend on an earlier resolved failure, deduplicates inline companion/parent targets, and keeps error-scoped dependency fixes in the Error Fixer result. Version 2.59.13.47 restores the JavaScript Error Fixer to a strict error-scoped pipeline: it checks the failing script's real WordPress dependency chain first, uses targeted provider discovery only when needed, keeps page-wide silent dependency/lifecycle analysis in the separate Analyzer, and removes hardcoded WordPress symbol-provider shortcuts. Version 2.59.13.46 simplifies the Media queue summary to completed, pending, processing, and failed states, where completed includes queue items that required processing and items that were already up to date. Version 2.59.13.45 clarifies the Media batch summary cards with line-separated AVIF/WebP counts, an explicit Media queue items total with processed-state breakdown, and line-separated Target policy, Fallback, and Queue format labels. Version 2.59.13.44 makes successful Varnish capability proofs non-expiring until their configuration/capability fingerprint changes and removes proof-expiry/test-date presentation from the Varnish diagnostics UI. Version 2.59.13.43 fixes the WordPress 6.7+ early translation-loading notice in the pre-init Varnish ESI capability status path. Version 2.59.13.42 fixes Overview → Warm Site so it honors the Also warm CSS bundles preference and resumes the matching HTML-only or HTML+CSS full-site job. Version 2.59.13.41 fixes the WordPress 6.7+ early translation-loading notice for the query-combination-limit warm bypass message without changing cache admission or registry GC behavior. Version 2.59.13.40 adds bounded query-string cache admission with configurable Combination Levels 1/2/3/4/ALL (default 3), live safe variant-space calculation, and independent catch-up garbage collection for expired inactive cache-asset registry rows. Version 2.59.13.39 fixes Browser Runtime Scan report races by making the frontend collector the normal serialized writer, changing admin scan loops to read-only polling with collector final flush and direct snapshot only as a fallback, and adding a short bounded server merge-lock retry so real JavaScript errors are not dropped behind repeated 409 Conflict responses. Apply Optimal Settings now also enables Async external CSS while preserving the existing external-CSS exclusion list. Version 2.59.13.38 adds automatic CSS Bundle Exclusion selection to First-Run Setup and Apply Optimal Settings: after the initial homepage CSS warm, UltraCache profiles the generated bundle, appends up to two large (>50 KB) source exclusions while preferring a large theme stylesheet, then purges and rebuilds the homepage CSS bundle before continuing. Existing exclusions are preserved and count toward the two-entry automatic cap, preventing reruns from progressively expanding the list. Version 2.59.13.37 changes automatic JavaScript compatibility checking to an error-first one-click flow: Apply Optimal Settings and First-Run Setup now run hidden same-origin browser runtime checks across the homepage and a bounded set of navigation pages, leave JavaScript safeguard lists untouched when no runtime errors are captured, invoke Analyze HTML JS Dependencies only for pages that actually report runtime errors, and limit automatic repair/retest to three runtime verification passes. Version 2.59.13.36 removes the pre-apply Detected environment and Settings applied now review screens from the manual Apply Optimal Settings flow: clicking the Overview action now opens the setup modal and immediately analyzes, applies, prepares, and reports live progress without a second confirmation click. Version 2.59.13.35 makes First-Run Setup and Apply Optimal Settings preserve already-configured Object Cache and Varnish infrastructure on reruns: an existing UltraCache Object Cache backend is runtime-verified without backend/fallback/drop-in rewrites, an existing external object-cache drop-in is left untouched, Varnish configuration is not revalidated or resynchronized by setup-only settings saves, and automatic backend detection remains limited to sites without an existing Object Cache. Version 2.59.13.34 simplifies Warm Cache to three scope actions controlled by a persistent **Also warm CSS bundles** switch (default ON): Homepage, Configured Menu, and Full Site now warm HTML + the configured CSS bundle scope when enabled and HTML only when disabled; the same preference is reused by the Optimal Settings/Wizard preparation and JavaScript safeguard rewarm flow. Version 2.59.13.33 embeds the existing live Warm/Media/JavaScript progress panel inside First-Run Setup and Apply Optimal Settings during configured-menu warming, HTML JavaScript dependency analysis, and JavaScript safeguard purge/rewarm work, while leaving the normal standalone progress popup unchanged outside setup. Version 2.59.13.32 fixes Apply Optimal Settings compression reporting so a successful live detection of server-provided HTML compression is shown as **Server managed** instead of **Off**, while keeping UltraCache's own gzip/Brotli encoders disabled in that state. Version 2.59.13.31 expands the dashboard navigation to Overview, Cache, Javascript, Fonts & CSS, Media, Server, Automation, and Advanced; moves Activity Summary and Diagnostics into Advanced; restores LCP Diagnostics & Settings to Media; makes query-string caching open by default; separates automation into its own tab; turns advanced JavaScript/CSS/font controls and Quick start & examples into accordions; and places WooCommerce beside Javascript manipulation with additional breathing room around diagnostics. Version 2.59.13.30 refines the six-tab dashboard layout: Cache Statistics and general inclusions/exclusions move to Advanced; Support this plugin and Quick start & examples move to Overview; Activity Summary and Diagnostics sit side by side; advanced JavaScript, CSS-delivery, and font-runtime controls are separated into dedicated Advanced cards; and Server/diagnostic/tab spacing is normalized for a less cramped interface. Version 2.59.13.29 keeps the detailed setup guidance as a Manual Setup path alongside the automatic wizard/Apply Optimal Settings workflow and updates every setup, preparation, JavaScript, CSS, and query-string instruction to the new Overview / Cache / Frontend / Media / Server / Advanced tab locations. Version 2.59.13.28 reorganizes the UltraCache dashboard into addressable Overview, Cache, Frontend, Media, Server, and Advanced tabs while keeping running jobs mounted across tab changes; Overview now exposes Apply Optimal Settings, Flush All Cache, Warm Site, cache statistics, and recent activity. Version 2.59.13.27 adds the JavaScript Post-Install Assistant to both the First-Run Setup Wizard and Apply Optimal Settings: it runs the existing resumable HTML JavaScript dependency analyzer, automatically applies only deterministic Strong Suggestion safeguards, purges and rewarms before bounded rescans, and leaves a persisted final visible-interaction verification step for the administrator. Version 2.59.13.26 extends Optimal Settings and the First-Run Setup Wizard with automatic post-configuration preparation: Flush All, homepage and deterministic menu warming, background full-site warm start/resume, and resumable existing-media queue preparation using the existing warm/media engines. Version 2.59.13.25 adds a fresh-install-only First-Run Setup Wizard that automatically opens on the first UltraCache dashboard visit, persists a singleton welcome/analyze/configure/completed state across refreshes, reuses the existing read-only Setup Plan and Optimal Settings engine, and remains completely absent for upgrades or existing installations. Version 2.59.13.24 extends Apply Optimal Settings with live Media Library image validation, selecting AVIF with WebP fallback only when the real sample conversions pass, enabling tested upload conversion, applying Compact quality and a 1920-pixel upload limit, enabling the recommended font optimizations, and activating WooCommerce/MailerLite optimizations only when those integrations are detected. Version 2.59.13.23 adds deterministic automatic warm-scope configuration to Apply Optimal Settings: a clear primary/main/header frontend menu is selected at Depth 1 and the canonical Homepage, Selected menu URLs, Pages, Posts, and Categories sources are saved, while ambiguous menu assignments preserve the existing menu selection instead of guessing. Version 2.59.13.22 makes Apply Optimal Settings automatically live-test HTML compression, select server-managed/Brotli/gzip delivery only from a determinate probe, and runtime-verify the selected Object Cache backend with rollback to the previous configuration on verification failure. Version 2.59.13.21 adds a review-first Apply Optimal Settings modal that consumes the setup planner, separates detected capabilities from settings applied now, and reports phased apply progress before completion. Version 2.59.13.20 adds a read-only Setup Detection / Planning Engine that maps server, object-cache, compression, media, integration, external-cache, menu, and warm-up capabilities into deterministic recommendations without applying settings. Version 2.59.13.19 replaces the settings Profile presets with a single Apply Optimal Settings workflow that preserves infrastructure credentials, schedules, exclusions, safeguards, and user-maintained lists while retaining deterministic Object Cache detection. Version 2.59.13.18 fixes the WordPress 6.7+ early translation-loading notice by moving the public warm-runtime upgrade reset from `plugins_loaded` to early `init`. Version 2.59.13.17 fixes the PHP 8.4 implicit-nullable deprecation in the Media Conversion Test REST endpoint. Version 2.59.13.16 hardens Media Library Replacement schema creation for MySQL/MariaDB environments by explicitly using InnoDB, bounding the two widest reference indexes below 1000 bytes, and replacing the database-side MD5 backfill with PHP hashing. Version 2.59.13.15 makes Strong lifecycle findings emitter-timing-aware so DOM-ready, immediate, and deferred callback emissions are treated differently, while callback/unknown races are suppressed. Version 2.59.13.14 removes the obsolete 80-file ceiling from the resumable HTML JavaScript dependency analyzer so every prepared local JavaScript candidate is processed before correlation. Version 2.59.13.13 fixes resumable JavaScript evidence-to-script identity mapping and makes exact safeguard actions write exactly the displayed Strong Suggestion target. Version 2.59.13.12 adds Help guidance for JavaScript functionality failures that produce no Console errors, pointing administrators to Analyze HTML JS Dependencies and cautioning against blindly applying every finding. Version 2.59.13.11 fixes the resumable Analyze HTML JS Dependencies settings-integrity type error and surfaces its real batch/cache progress through the shared Warm/Media progress popup. Version 2.59.13.10 hardens Strong Suggestions so only deterministic Delay/Defer ordering conflicts are promoted, merges independent evidence for the same target into one finding, suppresses same-script self-correlation, and keeps uncertain async races out of the focused result stream. Version 2.59.13.09 adds phase-aware progress, automatic dashboard-refresh resume, and JavaScript-settings integrity checks to the resumable Analyze HTML JS Dependencies workflow. Version 2.59.13.08 consolidated that scan around one compact per-script evidence registry, so each local JavaScript file is read or cache-resolved once per scan and final correlation reuses that same evidence. Version 2.59.13.07 added persistent per-file lifecycle-analysis caching keyed by local file freshness so unchanged JavaScript reuses extracted evidence across scans. Version 2.59.13.06 makes Analyze HTML JS Dependencies resumable and batch-bounded so local JavaScript lifecycle inspection is split across short persisted iterations instead of one monolithic request. Version 2.59.13.05 added focused Strong Suggestions and generic event-dispatch wrapper analysis for silent Delay/Defer initialization races. Version 2.59.13.04 updated the Donate link and Warm Cache installation guidance. Version 2.59.13.03 added the selected page's real WordPress script dependency graph and local JavaScript lifecycle listener/emitter analysis.

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
- Optional native LiteSpeed HTML caching, mutually exclusive with Apache Static HTML Delivery, with managed lookup and conservative bypass rules, Fresh TTL response control, isolated orig/WebP/AVIF cache keys, signed site-tag and exact-URL invalidation, automatic stale exact-tag regeneration governed by Automation Stale While Revalidate, automatic targeted affected-page refill and shared site-warm population through the generic Automation warm pipeline, a per-bucket MISS/HIT behavior test, bounded production counters, and recent operation history.
- Optional administrator-configured Varnish integration with scheme-preserving HTTP/HTTPS endpoints, isolated generation-canary verification for managed HTTP exact-URL invalidation, candidate-only endpoint discovery, behavior-gated runtime invalidation, site-wide scopes only when native admin BAN or a separate topology proof supports them, a bounded persistent invalidation queue, unified resumable page-warm pipeline, Fresh TTL-derived Varnish lifetime and stale refresh controls, site-warm Varnish prewarm, HTTP soft-purge/SWR-gated refresh ahead, integrated refill inside warm-after-flush page processing, compact production outcome/strategy metrics, bounded parent-cache performance snapshots, and live queue counters.
- Automatic capability-gated public and private ESI fragments with registered renderers, signed same-origin fragment URLs, bounded scalar context, per-fragment public TTL, cookie-scoped private session transport, inline fallback HTML, cache-file ESI sidecars, end-to-end Varnish capability verification, an automatic WooCommerce classic mini-cart adapter, and passive sampled render-cost telemetry with rolling 24-hour visibility.
- Dashboard and WP-CLI diagnostics, including persistent browser-observed LCP mapping, warm-refresh inspection, and native LiteSpeed transport, behavior-test, hard/stale purge, refill, and cache-signal telemetry.

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

UltraCache 3.08.04 automatically recognizes both server-rendered classic mini-carts and WooCommerce's canonical empty cart-widget placeholder. For the canonical widget path, the final WordPress template-enhancement pass matches only an empty `div.widget_shopping_cart_content`, preserves its existing theme-owned attributes/wrapper, and inserts the signed private ESI fragment inside it. Server-rendered mini-carts continue to use WooCommerce's semantic `woocommerce_before_mini_cart` / `woocommerce_after_mini_cart` contract. Bounded passive secondary fragments such as theme cart counters and badges are still discovered from `woocommerce_add_to_cart_fragments` and are applied only when a primary mini-cart ESI target exists. No theme name or theme selector catalogue is maintained. The explicit PHP helpers and shortcodes above remain available as manual adapters.

The adapter registers `woocommerce_items_in_cart`, `woocommerce_cart_hash`, and the `wp_woocommerce_session_` cookie prefix as its complete private scope. The Varnish card's copyable rules remove those cookies from the shared parent and restore them only for the private fragment. Test Varnish verifies the three-cookie transport before the adapter can leave fallback mode.

Classic automatic and explicit adapter renders ensure `wc-cart-fragments` remains available on pages where ESI is active, so native add/remove-cart events can continue to refresh cart state. UltraCache's optional empty-cart suppression and cart-fragment delay are disabled only for those ESI mini-cart pages. The block-based Mini-Cart is a separate Store API/Interactivity integration and is not converted by this classic integration.

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
2. Open the dashboard from the WordPress admin menu or admin bar. A fresh installation opens the **Setup Wizard** automatically. Existing installations can launch that same Wizard later from **Overview → Start Wizard**.
3. Run the Wizard. It analyzes the environment, applies the recommended configuration, lets you select the Object Cache backend and warm-up scope through the existing UltraCache subsystems, validates HTML compression and image conversion, and applies detected WooCommerce/MailerLite integrations.
4. Website preparation also reuses the normal engines. Warm-up can be **Homepage**, **Homepage + Menu**, or **Full Site**, using the same menu/depth/source settings and warm runners exposed in Warm Cache. Homepage AVIF/WebP conversion runs live through the shared Batch Conversion mechanism.
5. Setup runs the same Browser Runtime Scan and JavaScript Error Fixer self-healing cycle used by the standalone Javascript diagnostic, with a maximum of six scans and an early stop when zero runtime errors remain.
6. Manually maintained infrastructure credentials, schedules, exclusions, safeguards, and other user-maintained lists remain authoritative unless the Wizard exposes that normal setting for the user to change explicitly. Configure Varnish separately when present.
7. Re-run **Overview → Start Wizard** whenever you want the same analyzed configuration and preparation flow again. The Wizard is an orchestration UI over the normal UltraCache subsystems; it does not maintain parallel setup workers or duplicate settings.

### Must-do post-install check

The automated setup cannot prove that visible browser interactions look and behave correctly. After setup, open the public website and verify the menu/mobile navigation, sliders or popups, forms and search, and cart/checkout/account interactions when applicable.

If an interaction is still broken, use the visible **Browser Runtime Errors / Runtime Scan** or **Console Error Handler** in **JS Defer / Delay Safeguards & Diagnostics** for that exact page. Residual runtime errors that do not map to a deterministic automatic safeguard are left for review rather than triggering speculative exclusions.

The fixed **Help for the installed version** button in the lower-right corner of the dashboard opens the detailed setup, post-install guide, complete FAQ, and a **Manual Setup** checklist with the current tab location of every major setup step.


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
