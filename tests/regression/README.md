## 3.13.12 Runtime Scan Auxiliary Refill Graceful Failure

- Runtime Scanner preparation reuses the shared foreground `runtime_scan` session to select warm-pipeline semantics; no parallel Wizard-specific warm flow is introduced.
- HTML/page-cache warm remains required. Varnish/LiteSpeed refill failures become non-blocking warning stages only for Runtime Scan and preserve their exact failure class/message.
- Normal manual Warm / Flush+Warm refill failures remain strict.
- `runtime-scan-auxiliary-refill-graceful-regression.php` locks the context handoff, stage semantics, and warning propagation.

## 3.13.08 Force-Refresh Warm Single-Transform Contract

- Authenticated warm loopback bodies explicitly identify when final HTML transformation already ran.
- `warm_url()` must not transform a `final-v1` response body again.
- Force-refresh warms do not derive multiple media buckets from one already-final response.

## 3.13.06 Runtime Scanner Graceful-Failure Reset

- Real Cookie Banner and Complianz credentialless scanner-state cleanup remains best effort but never aborts a browser measurement when cleanup cannot be verified.
- Exact consent-reset failure reasons are retained for diagnostics and surfaced as non-blocking target warnings.
- Missing collector / incomplete browser measurement returns a structured unresolved target instead of throwing out of the multi-target Error Corrector.
- Server-side final URL resolution and one fresh measurement frame remain intact; no contaminated-context rebinding is restored.

## 3.13.05 Dynamic Script Completion Contract

- Runtime-created DELAY/DEFER scripts keep the same DOM element through release, preserving creator-owned `onload` / `onerror`, `addEventListener` callbacks, Promise completion, and retained element references.
- Runtime-created inline scripts are also revived by reinserting the same object; static HTML placeholders keep the established replacement path.
- `dynamic-script-completion-contract-regression.js` locks same-node external/inline release and verifies there is no Slider Revolution/vendor hardcode.

## 3.13.04 Scanner-first DELAY Dependency Ordering

- Registered DELAY scripts carry their real WordPress dependency handles into the browser loader without changing their selected lane.
- The delayed loader applies stable provider-before-consumer ordering and preserves WordPress family order (`before/data/translations/external/after`).
- Interaction release holds a consumer when one of its delayed providers remains ineligible; full release keeps consumers of delayed third-party providers in the late lane rather than pulling providers earlier.
- Runtime Scan records observed delayed execution sequence and prefers that evidence over DOM proximity when resolving competing providers.
- Missing Unified Inline Registry code fails closed: the occurrence stays inert instead of executing an empty script.
- No hidden vendor defaults/exclusions are added; RCB/Complianz compatibility and visible JavaScript list contents are unchanged.

## 3.13.02 Complianz Compatibility

Complianz Compatibility is an explicit OFF-by-default integration switch. The Setup Wizard silently enables it only on positive Complianz Lite/Premium detection. Only Complianz-owned cookie-banner/TCF/router/postscribe infrastructure is NATIVE; consent-released service scripts remain under the unified runtime firewall. Runtime Scan clears only isolated Complianz consent state, and real settings/banner changes invalidate UltraCache. Async Consent Banner CSS remains independent.

## 3.13.01 Real Cookie Banner Wizard Integration / UI Placement

- Setup Wizard detects active Real Cookie Banner Lite/Pro and only enables the existing compatibility switch on positive detection.
- No dedicated wizard UI is added.
- The manual compatibility switch lives in Advanced → Advanced settings.
- Existing 3.12.38 compatibility routing and scanner contracts remain unchanged.

## 3.12.38 Real Cookie Banner Compatibility Layer

Run:

```bash
php tests/regression/real-cookie-banner-compatibility-regression.php
node tests/regression/runtime-scan-first-load-isolation-regression.js
```

The explicit Real Cookie Banner switch protects only RCB-owned banner/blocker/vendor/TCF infrastructure. Service scripts released after consent remain subject to the canonical visible NATIVE / DEFER / DELAY policy and the 3.12.34 runtime firewall. Runtime Scan resets only its credentialless `real_cookie_banner*` storage/cookie partition before each measurement and fails closed when that reset cannot be verified. RCB configuration/revision changes invalidate UltraCache without creating consent-cookie page-cache variants or mutating visible JavaScript lists.

## 3.12.37 Runtime Scan First-Load Isolation

Run:

```bash
php tests/regression/runtime-scan-server-url-resolution-regression.php
node tests/regression/runtime-scan-first-load-isolation-regression.js
```

Runtime Scan no longer uses the measurement iframe to resolve the final frontend URL. Same-site redirects are resolved server-side before token creation; the browser gets one fresh credentialless measurement lifecycle, and collector injection failure is discarded rather than rebound in the same scanner context. The progress popup is driven by the real target-start callback and displays the active target number/count, label, and URL.

## 3.12.18 Runtime Bundle Activation Fix

Run:

```bash
php tests/regression/runtime-bundle-activation-transport-regression.php
node tests/regression/runtime-bundle-module-activation-regression.js
node tests/regression/runtime-bundle-delay-release-regression.js
```

These contracts verify that runtime activation metadata remains attached to the external bundle `<script>` for diagnostics, that NATIVE/DEFER bundles self-activate their compile-time requested module sets without depending on `document.currentScript`, that runtime bundle dependencies preserve NATIVE → DEFER → DELAY order, that the Runtime Scan collector installs and survives the controlled final-URL rebind state change, and that the existing 2-second Auto Release path actually requests a delayed external script after the DEFER loader activates. No JavaScript routing policy or release semantics are changed.

## 3.12.17 Lighthouse Recovery Validation

Run:

```bash
php tests/regression/lighthouse-recovery-validation-regression.php
php tests/manual/js-architecture-validation.php lighthouse.json runtime-scan.json
```

The manual report combines uninstrumented Lighthouse performance metrics with a separate verified Runtime Scan classification payload. It reports UltraCache render-blocking resources, NATIVE/DEFER/DELAY distribution, and unclassified script escapes without loading any new production code. A Lighthouse-only invocation is allowed, but classification fields remain unknown rather than being inferred.

## 3.12.16 Generic Dependency Safeguards Audit

Run:

```bash
php tests/regression/generic-dependency-safeguards-regression.php
```

WordPress inline-before/after/data/translations companions no longer force their registered external script NATIVE. Delayable companions inherit the external script lane and use ordered DEFER or DELAY execution. The old blanket `has enqueued dependents => cannot DELAY` check is replaced by a final rendered dependency invariant: a registered dependency may stay DELAY when its dependent is also DELAY, and is promoted only as early as required when a dependent resolves to DEFER or NATIVE. Registered dependency-connected scripts are never parallelized with `async`, and delayed dependency groups disable Delay parallel execution through explicit ordered metadata. Visible lists, explicit integrations, Auto Release, and Minimum Delay Release remain authoritative/unchanged.

## 3.12.15 Final Hidden Vendor Rules Audit

- `final-hidden-vendor-rules-regression.php` locks the generic scheduler to vendor-neutral policy, verifies Async external transport uses the visible third-party list, and requires Elementor lazy-background behavior to live behind the explicit Elementor Compatibility integration module.

## 3.12.14 Escape Detection / Classification Audit Mode

Run:

```bash
php tests/regression/classification-audit-mode-regression.php
node tests/regression/classification-audit-mode-regression.js
```

Only verified Runtime Scan requests enable detailed JavaScript classification recording. Registered scripts expose scan-only route metadata, while the parser-early Dynamic Finder exposes only a nullable recorder hook that the already diagnostics-only Runtime Scan collector attaches during a verified scan; delayed executable replacements preserve that provenance. The existing Runtime Scan collector compares the resulting NATIVE / DEFER / DELAY records with actual `PerformanceResourceTiming` entries whose initiator type is `script`. Any script request with no matching classification record is reported in `classificationAudit.unclassifiedRequests` and is treated as a Finder/Router escape bug. Normal production creates no audit sink or audit DOM metadata and adds no network/persistence channel.

## 3.12.13 Scanner as Teacher

Run:

```bash
php tests/regression/scanner-visible-policy-regression.php
```

Runtime/console diagnostics may store findings in the DB-backed Diagnostic Queue, but stored findings are evidence only and are never read by the JavaScript execution engine. Manual suggestion buttons only edit one of the visible JavaScript policy drafts. The explicit self-healing scan can still apply deterministic fixes automatically, but each automatic decision must carry a strict visible-policy descriptor and is rejected unless it writes exactly one of `delaySafeThirdPartyJsPatterns`, `deferJsForceList`, or `deferJsExcludeList`. There is no hidden per-script override channel.

## 3.12.07 Hidden Consent Policy Removal

Run:

```bash
php tests/regression/consent-control-plane-scope-regression.php
php tests/regression/delay-classification-contract-regression.php
node tests/regression/dynamic-tracker-injection-gate-regression.js
node tests/regression/cmp-proof-dynamic-tracker-config-regression.js
php tests/regression/js-policy-contract-regression.php
```

The generic JavaScript engine no longer recognizes Complianz, Real Cookie Banner, WP Consent API, Site Kit consent mode, CookieYes, Cookiebot, Iubenda, OneTrust, or semantic consent directives as a hidden scheduling authority. Consent-management scripts now follow the same NATIVE / DEFER / DELAY router, visible Defer Instead of Delay / Do Not Defer or Delay lists, generic HTML/JavaScript semantics, and explicit author opt-outs as other scripts.

The temporary 3.11.27/3.11.28 dynamic tracker gate is removed. The parser-early interaction bootstrap no longer monkey-patches DOM insertion methods or carries tracker/CMP pattern payloads; it only captures configured pre-defer visitor interactions. The existing Auto Release behavior remains frozen and unchanged.

## 3.12.03 Central Script Router

Run:

```bash
php tests/regression/central-script-router-regression.php
php tests/regression/js-policy-contract-regression.php
```

The registered-script `script_loader_tag` path now delegates to one central router that emits exactly one `NATIVE`, `DEFER`, or `DELAY` lane together with a reason and an application action. Route application performs transformations only; it does not make a second scheduling-policy decision. Existing 3.12.02 policy debt is intentionally preserved in this release and is explicitly labeled as legacy input inside the router so later roadmap steps can remove or re-home it without mixing policy cleanup with the authority refactor.

No production telemetry or extra frontend request is added by the router. Existing async-script behavior is represented as transport inside the `NATIVE` lane rather than introducing a fourth execution lane. The existing Auto Release contract remains frozen and untouched.

## 3.12.02 JavaScript Policy Contract

Run:

```bash
php tests/regression/js-policy-contract-regression.php
```

This development-only contract establishes the 3.12 JavaScript authority model without changing production scheduling behavior: every executable script belongs to exactly one of NATIVE / DEFER / DELAY; visible Defer Instead of Delay and Do Not Defer or Delay lists are authoritative; generic vendor identity is not an allowed hidden policy basis; and only HTML/JavaScript semantics, explicit author opt-outs, or explicit integration switches may bypass normal strategy classification.

The suite originally froze a nine-entry `tests/architecture/js-policy-debt.php` manifest representing known hidden scheduling debt. By 3.12.07 the hidden consent/CMP classifier/native-lifecycle rules and temporary dynamic-tracker gate are removed; the manifest now tracks only the remaining explicit vendor-policy debts.

The existing Auto Release setting is explicitly frozen by this contract. Future Minimum Delay Release work may add a gate after a release request, but must not alter Auto Release UI, defaults, migration, timer, or trigger semantics.


# UltraCache regression tests

These scripts are development/CI checks only and are never loaded by the WordPress plugin runtime.

Run the media state-machine contracts with:

```bash
php tests/regression/media-state-machine-regression.php
```

Run the Retry Failed Media backend/UI contracts with:

```bash
php tests/regression/retry-failed-media-regression.php
```

The suites protect the 3.11.x media-on-demand hardening contract, including terminal queue states, failed-only retry, actionable worker dispatch, logical affected-page normalization, discovery budgets, completed-media fast paths, zero-introspection schema readiness, and the always-visible/disabled-at-zero Retry failed media UI contract.

Run the no-hidden-consent-policy contracts with:

```bash
php tests/regression/consent-control-plane-scope-regression.php
```

This suite protects the 3.12.07 contract that consent/CMP identity alone cannot force NATIVE or DEFER. Consent-management assets follow the normal router; visible lists and explicit author opt-outs remain authoritative.

Run the CMP metadata / optimizer opt-out contracts with:

```bash
wp eval-file tests/regression/consent-optimizer-optout-regression.php
```

This suite protects the 3.11.18 contract that generic `data-consent-*` categorization metadata is not an optimizer opt-out, explicit skip markers remain respected, and CMP-disarmed `type="text/plain"` payloads remain untouched by the Delay JS MIME gate.

Run the Delay JS classification policy contracts with:

```bash
php tests/regression/delay-classification-contract-regression.php
```

This suite protects the 3.12.07 contract that Delay classifiers contain no hidden consent/CMP fingerprints. User-editable third-party patterns, Defer Instead of Delay, Do Not Defer or Delay, and generic script semantics remain available.

Run the combined production-load contract validation with:

```bash
php tests/regression/production-load-validation.php
```

This final suite validates the completed-media/crawler-load contract across media terminal fast paths, actionable-only worker dispatch, explicit-only repair, bounded discovery/page refs, CSS cache-first lookups, and version-trusting runtime schema readiness for media, locks, analytics, LCP, action jobs, and the warm queue.
Run the Delay JS runtime lane/release-order contracts with:

```bash
node tests/regression/delay-runtime-lane-order-regression.js
```

This suite protects the 3.11.20 runtime ordering contract using the real production eligibility predicates: visitor interaction may release first-party and explicitly functional-third-party work, while safe/all/unknown external third-party analytics and marketing payloads remain outside the interaction lane. It also locks DOM-ready gating and serializes a pending full release behind an in-progress interaction release.


For controlled Lighthouse/runtime A/B measurement, see:

```text
tests/manual/README.md
```

The 3.11.21 manual tooling is development-only and does not alter plugin runtime behavior.

Run the frontend helper loading-policy contracts with:

```bash
node tests/regression/frontend-helper-loading-policy-regression.js
```

This suite protects the 3.11.25 helper audit: the full Delay JS loader, lazy third-party iframe runtime, and full LCP observer use native `defer`; parser-early interception helpers remain blocking only where required; and a tiny conditional interaction bootstrap preserves eligible input that happens before the deferred Delay JS loader initializes.

## Forced-reflow source mapping (3.11.26)

`forced-reflow-source-mapping-regression.php` protects the development-only source mapper used to resolve Lighthouse `(index):line:column` forced-reflow entries against the exact saved served HTML. The contract keeps external scripts separate from inline document ownership, preserves unknown ownership instead of guessing, ranks exposed reflow durations, and handles one-based DevTools versus zero-based protocol line numbering explicitly.
- `frontend-runtime-registry-regression.php` — 3.12.06 canonical UltraCache frontend runtime registry: complete `assets/js` inventory, unique handle/asset mapping, explicit NATIVE/DEFER/DELAY lane, dependencies/reason, current-request requested state, and generated lane-bundle consumption.
- `frontend-runtime-bundle-regression.php` — 3.12.06 generated NATIVE/DEFER/DELAY runtime bundles: requested/reserved-only module inclusion, at most one normal-path asset per active lane, early queue-position reservation, dependency-bound zero-network activation, inactive-module exclusion, delayed-lane omission when empty, and standalone fallback if persistence fails.
- `frontend-runtime-native-lane-audit-regression.php` — 3.12.06 strict UltraCache parser-early audit: only unrecoverable pre-defer interaction/LCP capture plus explicit diagnostic scan collection may remain NATIVE; eight non-critical helpers are required to use DEFER.

## 3.12.08 Visible Lists Final Authority

The visible JavaScript lists now have explicit final precedence across the registered-script router and ordered HTML rewrite passes: `Do Not Defer or Delay` resolves NATIVE and wins on overlap; `Defer Instead of Delay` resolves DEFER only when no NATIVE visible rule applies; otherwise the selected strategy/default classifier decides. The 3.09.04 hidden-consent-era default migration is reversed only for untouched default lists, restoring `gtag(` / `dataLayer` and Complianz/CookieYes fragments to the user-editable third-party pattern fields. Customized lists are never overwritten.


## 3.12.09 Explicit Integrations Only

- `explicit-js-integrations-regression.php` verifies WooCommerce variable-product protection is gated by the visible WooCommerce JS Compatibility switch.
- The former Contact Form 7 and author-arc hidden native fallbacks are visible/editable Do Not Defer or Delay entries and are absent from the generic delay classifier.
- Hidden vendor-specific JavaScript scheduling debt is zero.
- Existing Auto Release configuration remains frozen and unchanged.


## 3.12.10 Generic Dynamic Script Finder

Run:

```bash
node tests/regression/dynamic-script-finder-regression.js
```

A parser-early capture bootstrap covers runtime-created executable `<script>` insertion through `appendChild`, `insertBefore`, `replaceChild`, connected `script.src` / `setAttribute('src')`, and complete `document.write` / `writeln` script fragments. Before the deferred loader is available it immediately honors only true semantics/author opt-outs and the visible Do Not Defer or Delay list; every other executable runtime script is captured inert. The existing DEFER loader then owns full NATIVE / DEFER / DELAY classification using the visible lists and active generic Delay settings, and remains the only Delay executor. The finder adds no standalone network request and no production telemetry. Auto Release is unchanged.

## 3.12.11 Unified JavaScript Routing

Run:

```bash
php tests/regression/unified-js-routing-regression.php
```

Registered WordPress scripts and runtime-created scripts now consume one server-generated declarative policy snapshot. The ordered rule table owns visible NATIVE/DEFER precedence, Delay All, safe/functional/all-third-party Delay, first-party non-critical Delay, disabled-optimization fallback, and the default DEFER lane. Server-side WordPress dependency/integration state and browser-side DOM/origin state are facts supplied to that same table rather than separate policy trees. The legacy third-party/inline/non-critical helpers are adapters of the unified evaluator. No production telemetry or extra network request is added; Auto Release remains unchanged.



## 3.12.12 Minimum Delay Release

- `minimum-delay-release-settings-regression.php` locks the visible `Minimum Delay Release` setting lifecycle: Disabled/1/2/3/4 seconds, default Disabled, REST/import-export/CLI/runtime transport, and the frozen existing Auto Release option set.
- `minimum-delay-release-regression.js` executes the production gate helpers with a controlled clock and verifies that early interaction/full release requests are retained until the absolute page-navigation threshold, interaction resumes before a pending full request, Disabled adds no timer, and the gate never creates a release request by itself.
- Existing Auto Release timer generation, After page load behavior, and event-trigger registration remain unchanged; the new setting is an additive gate in the existing delayed-JavaScript state machine and adds no frontend request.

## 3.12.26 Final JS execution identity dedupe

```bash
php tests/regression/final-js-execution-identity-dedupe-regression.php
```
## 3.13.11 Setup Wizard Runtime Scanner Orchestration

- Setup Wizard delegates JavaScript verification to the shared `runRuntimeSiteScanAction()` result and does not redefine failed/unresolved target semantics.
- Runtime Scanner warnings and exact per-target reasons are non-blocking in the Wizard and advance to Verify.
- JavaScript is not a persisted Wizard failure-resume step; legacy failed JavaScript states normalize forward to Verify, while genuinely interrupted in-progress JavaScript runs may resume the same shared scanner action.
