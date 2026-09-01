## 3.12.17 JavaScript architecture validation

Run an ordinary **uninstrumented** Lighthouse capture for performance numbers and a separate verified Runtime Scan for classification evidence, then run:

```bash
php tests/manual/js-architecture-validation.php lighthouse.json runtime-scan.json
```

The report shows Performance, FCP, LCP, TBT, CLS, Speed Index, DOM size, forced-reflow duration, UltraCache render-blocking request count/transfer/time, NATIVE/DEFER/DELAY classification counts, and the number of ResourceTiming script requests that escaped classification. Runtime Scan is optional; when omitted the tool does not invent lane or escape evidence. Do not use an instrumented Runtime Scan page as the Lighthouse performance run.

# UltraCache controlled performance A/B tools

These files are development/manual diagnostics only. They are never loaded by the WordPress plugin runtime.

## Lighthouse JSON comparator

Export each DevTools Lighthouse result as JSON and run:

```bash
php tests/manual/compare-lighthouse.php 3.06.26.json 3.11.16.json 3.11.21.json
```

Keep the test state identical: same URL, browser/device profile, throttling, consent state, interaction state, warm server/page cache, and at least three independent runs per state. Compare medians rather than one best run.

The comparator reports Performance, FCP, LCP, TBT, CLS, Speed Index, DOM size, and Google Tag Manager transfer/main-thread totals when Lighthouse exposes them in the report.

## Browser runtime probe

Run `performance-ab-browser-probe.js` from DevTools Console/Snippets on the same page state. Capture one snapshot before the delayed-JS trigger and another immediately after the trigger.

The probe stores snapshots in:

```js
window.__ultracachePerformanceABSnapshots
```

It records tracker/CMP resource start times, delayed-script metadata, UltraCache dataset markers, DOM/Swiper counts, and dataLayer consent/boot indexes. This is evidence for classification/release timing; it is not a substitute for Lighthouse's filmstrip/Speed Index calculation.

## DOM/runtime attribution audit (3.11.22)

`dom-runtime-attribution.js` is an instrumentation run for the specific case where Lighthouse shows a large post-load DOM increase or Swiper/Slider Revolution work inside the Speed Index window.

Run it as a DevTools Snippet **before** the delayed-JS trigger, reproduce the normal interaction/full release, then run:

```js
window.__ultracacheDomRuntimeAudit.report();
```

To export the full evidence as JSON:

```js
copy(window.__ultracacheDomRuntimeAudit.exportJson());
```

The audit records:

- DOM counts at install, DOMContentLoaded/load, UltraCache delayed-script start/lane-done/all-done, and manual checkpoints.
- Every inert delayed-script replacement observed during the run, including handle/src/delay reason and an inferred interaction/first-party/third-party lane.
- Mutation batches with net element growth and Swiper/Elementor/Slider Revolution/Slick/Splide counts.
- Large or slider-related synchronous DOM operations grouped by JavaScript call-stack source, so a final `1.6k -> 2.1k` DOM increase can be attributed to the code path that actually created it instead of inferred from the final markup.

The DOM hooks intentionally add runtime overhead. **Do not compare Lighthouse scores from an instrumented run.** Use a normal uninstrumented run for performance numbers and the instrumented run only for attribution.

## 3.11.23 visual-init decision gate

`analyze-dom-runtime-attribution.php` consumes the JSON exported by `dom-runtime-attribution.js` and ranks delayed-script execution windows by source-proven DOM growth, visual-runtime identity, lane, and execution duration. It also surfaces synchronous DOM-operation stack sources.

Run:

```text
php tests/manual/analyze-dom-runtime-attribution.php dom-runtime-audit.json
```

The analyzer intentionally refuses to authorize a generic Swiper/Slider/Elementor scheduler or CSS workaround when no source-proven visual-init culprit crosses the evidence threshold. A production visual-init patch should target only the demonstrated handle/src/lane behavior, then be re-tested with the controlled A/B tooling.

## 3.11.26 forced-reflow source mapping

`analyze-forced-reflow-source.php` maps Lighthouse forced-reflow/layout source locations back to the exact `<script>` block in a saved copy of the served HTML. It reports the document line/column, reflow duration when exposed by Lighthouse, inline script line range, script id/handle-style identifier, a bounded content snippet, and ownership confidence.

Run:

```text
php tests/manual/analyze-forced-reflow-source.php lighthouse.json served.html --document-url=https://example.test/page
```

Use the **same served page state** as the Lighthouse run. A later second request can differ because of personalization, consent state, cache variants, or plugin output. If the source is external, or if the reported document line cannot be mapped into a script block, the tool deliberately leaves ownership unresolved instead of guessing.

Ownership is evidence-based only: known script ids/src/content signatures can produce a high/medium-confidence owner; an otherwise unknown inline block stays `unknown`. The tool also handles the common mismatch between one-based DevTools display lines and zero-based protocol locations by reporting which line basis matched.
