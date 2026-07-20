/* UltraCache Admin - Option help registry and label helpers */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function' || typeof admin.get !== 'function') {
		throw new Error('UltraCache admin namespace was not loaded.');
	}

	const core = admin.get('core');
	if (!core) {
		throw new Error('UltraCache admin core module was not loaded before help.js.');
	}

	const { h, classNames } = core;

	function renderLabelWithHelp(label, helpText, className) {
		if (!label) {
			return null;
		}

		const text = String(helpText || '').trim();
		return h('span', { className: classNames('uc-label-with-help', className || '') }, [
			h('span', { key: 'label' }, label),
			text ? h('span', {
				className: 'uc-help-icon',
				title: text,
				tabIndex: 0,
				'aria-label': text,
				key: 'help',
			}, 'i') : null,
		]);
	}

	function normalizeOptionHelpKey(value) {
		return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
	}

	function makeOptionHelp(lines) {
		return lines.join('\n\n');
	}

	const OPTION_SPECIFIC_HELP = {};
	function addOptionHelp(label, lines) {
		OPTION_SPECIFIC_HELP[normalizeOptionHelpKey(label)] = makeOptionHelp(lines);
	}

	[
		['All Off', [
			'What it does: turns off the optimization modules that the profile system controls. It does not erase your saved lists, diagnostics, scheduling choices, or Varnish settings.',
			'Why it helps: this is the clean testing switch. If something looks wrong, All Off gives you a quiet baseline so you can turn features back on one group at a time.',
			'Watch for: because it disables speed features, PageSpeed can drop until you choose another profile or enable individual options again.',
		]],
		['Safe', [
			'What it does: chooses the careful preset. It keeps risky frontend JavaScript timing off, enables safer cache and object-cache helpers, and preserves your visible exclusion lists.',
			'Why it helps: it gives most sites a speed base without moving scripts too far from where WordPress printed them.',
			'Watch for: Safe is intentionally conservative. If the site is stable and you want higher scores, Balanced or manual CSS/JS controls can go further.',
		]],
		['Balanced', [
			'What it does: chooses the middle preset. It enables more CSS bundling and selected delayed JavaScript helpers, but does not turn on Delay all JS.',
			'Why it helps: the browser gets fewer blocking files and less early JavaScript work, which can improve LCP and TBT while staying testable.',
			'Watch for: still test menus, sliders, product pages, forms, and checkout because this preset changes more frontend timing than Safe.',
		]],
		['Aggressive', [
			'What it does: chooses the speed-first preset. It uses defer as the base JavaScript strategy, turns on targeted delay modules, uses aggressive CSS bundling, warms affected pages after content saves, and enables Apache Static HTML Delivery on compatible hosts.',
			'Why it helps: it tries to keep the first view focused on HTML, CSS, and the hero image instead of letting many scripts compete early.',
			'Watch for: aggressive settings need scanning and testing. If a script error appears, prefer Defer Instead of Delay first, then Do Not Defer or Delay only when defer still breaks it.',
		]],
		['Custom', [
			'What it does: appears when your saved settings no longer match a known preset exactly.',
			'Why it helps: it tells you that the site is now tuned by individual switches and lists, not by one simple profile recipe.',
			'Watch for: Custom is not bad. It just means the exact mix is yours, so keep notes when you test changes.',
		]],
		['Page Caching', [
			'What it does: saves public pages as ready-made HTML files. Later anonymous visitors can receive that saved page instead of asking WordPress to build it again.',
			'Why it helps: WordPress, the theme, plugins, and many database queries can be skipped on cache hits, so the server answers faster.',
			'Watch for: pages with carts, accounts, checkout, previews, sessions, or unsafe cookies must stay out of public cache because their HTML can be personal.',
		]],
		['Warm affected pages after save', [
			'What it does: when a real content save happens, UltraCache can warm only the affected public pages after purging their old cache files.',
			'Why it helps: the next visitor is less likely to wait for a cold cache on the changed page or its directly related archive pages.',
			'Watch for: keep this off on worker-limited hosting unless you specifically want post-save warming. Manual and scheduled warm-up are separate controls.',
		]],
		['Browser Cache Headers', [
			'What it does: writes Apache .htaccess rules that tell browsers how long static files can be reused.',
			'Why it helps: returning visitors do not need to download the same images, CSS, JS, fonts, manifests, WASM, audio, or video again so soon.',
			'Watch for: these rules help repeat visits. They do not replace page cache, and servers that do not read .htaccess may need matching server or CDN rules.',
		]],
		['Apache Static HTML Delivery', [
			'What it does: lets Apache hand out the saved HTML file before PHP and WordPress wake up. Think of it like taking a ready page from a shelf.',
			'Why it helps: it can remove WordPress startup time for safe anonymous, queryless GET requests.',
			'Watch for: the rules deliberately skip query strings, unsafe cookies, login/admin/REST/AJAX paths, WooCommerce dynamic paths, cart, checkout, account, and session-like visits. PHP debug headers and PHP hit counters do not run for those server-level hits.',
		]],
		['HTML Compression', [
			'What it does: chooses whether UltraCache writes cached HTML using server-managed output, gzip, or Brotli where the server supports it.',
			'Why it helps: compressed HTML is smaller, so the first document can travel faster over the network.',
			'Watch for: compression must match what the browser asked for. If a server or proxy already handles compression, server-managed mode may be cleaner.',
		]],
		['Speculation Rules Prefetch', [
			'What it does: asks WordPress Core to add safe prefetch hints for likely next internal pages.',
			'Why it helps: the browser can quietly prepare a page before the visitor clicks it, making the next navigation feel faster.',
			'Watch for: UltraCache avoids logged-in users, query-string links, WooCommerce flows, admin-like paths, nofollow links, and excluded paths because those are not safe guesses.',
		]],
		['Cache Pages with Safe Tracking Cookies', [
			'What it does: allows public HTML cache when the only cookies involved are in your Safe Tracking Cookies list.',
			'Why it helps: analytics cookies should not force WordPress to rebuild the same public page again and again.',
			'Watch for: use this only for cookies that never change visible HTML. UltraCache still does not store or replay Set-Cookie headers.',
		]],
		['Enable Media Rewrite', [
			'What it does: rewrites frontend image URLs to AVIF or WebP according to the selected output mode, but only when the optimized files already exist.',
			'Why it helps: smaller image files can improve transfer time and LCP, especially for large product or hero images.',
			'Watch for: this does not convert images inside the visitor request. Upload conversion, batch conversion, or on-demand queueing must create the files first.',
		]],
		['Image Output Format', [
			'What it does: chooses the primary optimized image format UltraCache prefers: AVIF or WebP.',
			'Why it helps: AVIF is often smaller, while WebP is broadly compatible and remains the migrated default for older Automatic settings.',
			'Watch for: changing this affects which generated files are used and which formats the best queue policy creates.',
		]],
		['Fallback Format', [
			'What it does: chooses whether AVIF output falls back to WebP or to the original JPEG/PNG file. WebP output always falls back to the original JPEG/PNG file.',
			'Why it helps: WebP fallback keeps an optimized path for browsers that cannot use AVIF; JPEG/PNG fallback avoids generating an extra WebP layer.',
			'Watch for: WebP fallback is available only when Image Output Format is AVIF.',
		]],
		['Image compression level', [
			'What it does: chooses the encoder quality used for generated AVIF/WebP variants and Convert new uploads.',
			'Why it helps: higher levels preserve more visual detail; lower levels reduce transfer size more aggressively.',
			'Watch for: existing generated files keep their current quality until they are regenerated.',
		]],
		['Rewrite on upload', [
			'What it does: queues newly uploaded images and their registered thumbnail sizes for AVIF/WebP conversion.',
			'Why it helps: new media can be ready before visitors request it.',
			'Watch for: uploads may create background conversion work. Original images stay untouched.',
		]],
		['Convert new uploads', [
			'What it does: converts the actual uploaded image file to the selected Image Output Format during the WordPress upload flow.',
			'Why it helps: newly added media can start as WebP or AVIF immediately instead of waiting for batch conversion.',
			'Watch for: this changes the uploaded attachment file itself. If the selected encoder cannot produce the requested format, the upload fails with a visible diagnostic error.',
		]],
		['Queue Missing Media During Page Generation', [
			'What it does: while WordPress generates or regenerates page HTML, UltraCache queues missing AVIF/WebP variants and starts one background worker after the response finishes.',
			'Why it helps: missing optimized images discovered during page generation can be created without making the visitor request perform the conversion.',
			'Watch for: conversion runs separately in the background. WP-Cron remains a recovery fallback if loopback dispatch is unavailable.',
		]],
		['Pause after stale workers', [
			'What it does: sets how many stale media worker incidents may occur inside the rolling safety window before automatic media generation is paused.',
			'Why it helps: smaller values stop repeated failing workers sooner, while larger values allow more incidents before the global circuit opens.',
			'Watch for: use a whole number greater than zero. Quarantined items remain failed until they are retried explicitly.',
		]],
		['Safe CLS Dimensions', [
			'What it does: adds missing width and height to local images using WordPress attachment metadata first, then local file dimensions when needed.',
			'Why it helps: the browser can reserve the right space before the image loads, reducing layout jumps.',
			'Watch for: this is meant for normal local raster images. If a theme intentionally uses fluid images in unusual ways, check the first viewport and product grids.',
		]],
		['LCP Image Priority', [
			'What it does: finds the likely hero or LCP image and gives it early loading priority. It can add fetchpriority and a preload when safe.',
			'Why it helps: the most important visible image is discovered sooner, which can improve LCP.',
			'Watch for: if the wrong image is picked, add a Manual LCP selector so UltraCache knows which image matters most.',
		]],
		['Lazy load & async images', [
			'What it does: adds native lazy loading and async decoding to eligible images.',
			'Why it helps: below-the-fold images wait their turn, so the browser can focus on the first view.',
			'Watch for: when LCP Image Priority is enabled, UltraCache tries to lazy-load only images after the detected LCP image so the hero is not delayed.',
		]],
		['JavaScript Strategy', [
			'What it does: chooses the base JavaScript mode. Off leaves scripts alone, Defer lets the browser run eligible scripts after parsing, and Delay holds eligible scripts until the delayed queue releases.',
			'Why it helps: moving non-critical scripts later can reduce render blocking and main-thread pressure.',
			'Watch for: this control only changes the base Defer JS and Delay all JS settings. Third-party, local, LCP, and pattern-based delay controls stay independent.',
		]],
		['LCP Boundary Delay', [
			'What it does: uses the LCP image found by LCP Image Priority as a line in the HTML. Eligible local scripts printed after that line can be delayed.',
			'Why it helps: scripts after the hero image wait so the first visible content gets attention first.',
			'Watch for: this depends on LCP Image Priority. If a script after the boundary creates something visible above the fold, move it to Defer Instead or exclude it.',
		]],
		['Delay safe third-party JS', [
			'What it does: delays third-party analytics, pixels, ads, tracking, and marketing scripts that match the safe pattern list.',
			'Why it helps: those scripts usually do not need to block the first view, so delaying them can improve LCP and TBT.',
			'Watch for: tracking may fire later. If a tag must run immediately for consent, payment, login, or a critical form, protect it with a visible safeguard.',
		]],
		['Delay non-critical/local JS', [
			'What it does: delays selected same-site enhancement scripts like popups, filters, sliders, marketing helpers, and other local footer scripts.',
			'Why it helps: local extras stop competing with the browser while it builds the first view.',
			'Watch for: same-site does not always mean safe. If a local script defines a jQuery plugin or a global needed by later code, Defer Instead is usually better than Delay.',
		]],
		['Delay known functional third-party JS', [
			'What it does: delays matched third-party scripts that provide visible features such as cookie banners, captcha, maps, chat, booking, forms, popups, newsletters, or reviews.',
			'Why it helps: many of these widgets are not needed until the visitor scrolls, clicks, or uses the feature.',
			'Watch for: if a form, captcha, cookie banner, checkout, map, or chat must work immediately, exclude or defer-instead its script after testing.',
		]],
		['Delay all third-party JS', [
			'What it does: delays external scripts from third-party domains unless a visible safeguard protects them.',
			'Why it helps: it is a broad way to stop outside scripts from crowding the first page load.',
			'Watch for: this is powerful. Captcha, payments, consent, login, booking, maps, and critical forms often need special care.',
		]],
		['Event triggers', [
			'What it does: chooses which visitor actions can release the delayed JavaScript queue early.',
			'Why it helps: if someone clicks, scrolls, types, touches, or moves the pointer, the site can wake delayed scripts before the fallback timer.',
			'Watch for: leaving all events off gives pure timer-based release, which is good for repeatable testing.',
		]],
		['If no event happens, autostart JS after', [
			'What it does: sets the fallback timer for every delayed JavaScript queue.',
			'Why it helps: delayed scripts still run even if the visitor does nothing.',
			'Watch for: shorter timers are safer for functionality but less aggressive for speed. Longer timers protect the first view more but can delay widgets.',
		]],
		['CSS Bundling', [
			'What it does: creates local UltraCache CSS bundles from eligible stylesheet links.',
			'Why it helps: fewer CSS requests can shorten the render-blocking chain.',
			'Watch for: bundling changes how styles arrive. If layout breaks, add the stylesheet to CSS Bundle Exclusions or use a safer bundle mode.',
		]],
		['CSS Bundling Scope', [
			'What it does: chooses where generated CSS bundles are used: homepage only, shared site bundle, or per-page bundles.',
			'Why it helps: the right scope reduces request count without making one giant bundle serve pages that do not need it.',
			'Watch for: homepage only is safest. Per-page can be more accurate but creates more generated files.',
		]],
		['CSS Bundle Mode', [
			'What it does: chooses how brave UltraCache is when combining local CSS. Safe is careful, Aggressive includes more, and Full CSS Bundle goes furthest.',
			'Why it helps: broader bundling can remove more blocking requests.',
			'Watch for: bigger bundles can include CSS a page does not need or change order-sensitive layouts. Test the first viewport, product grids, menus, and sliders.',
		]],
		['Inline CSS Bundling', [
			'What it does: places the generated CSS bundle directly inside the cached HTML head instead of linking a file.',
			'Why it helps: the browser does not need a separate CSS request before painting.',
			'Watch for: large inline CSS makes the HTML document bigger. This can help small critical bundles and hurt huge bundles.',
		]],
		['Consolidate Remaining CSS', [
			'What it does: after the main CSS bundle is placed, UltraCache combines eligible leftover local CSS links into one extra file.',
			'Why it helps: it reduces the small tail of plugin or theme CSS requests that still block rendering.',
			'Watch for: protected hero, slider, and risky CSS should stay out. If a small stylesheet matters above the fold, exclude it.',
		]],
		['First Visit CSS Bundle Handling', [
			'What it does: decides what happens when a visitor opens a page before its CSS bundle exists.',
			'Why it helps: building on entry can avoid missing bundles, while async build avoids making the visitor wait as much.',
			'Watch for: do nothing is safest but may leave the first visit unbundled. Build on entry can cost time on the first uncached page.',
		]],
		['Async Remaining CSS', [
			'What it does: changes eligible low-risk CSS links to print/onload loading with a noscript fallback.',
			'Why it helps: the browser can paint sooner because those stylesheets stop blocking the first render.',
			'Watch for: layout-critical CSS should stay blocking. If the first view flashes, shifts, or loses styling, add the stylesheet to Async CSS Exclude List.',
		]],
		['Async external CSS', [
			'What it does: lets UltraCache async-load stylesheets from other domains.',
			'Why it helps: third-party CSS stops blocking your first render.',
			'Watch for: external CSS can still be visually important. Protect icon libraries, fonts, or layout CSS if the page flashes or changes late.',
		]],
		['Aggressive Async CSS', [
			'What it does: broadens async CSS rewriting to almost all remaining local stylesheet links, including late output.',
			'Why it helps: it attacks render-blocking CSS more strongly than normal Async Remaining CSS.',
			'Watch for: this is a speed-first option. Use the Async CSS Exclude List for any stylesheet needed to draw the first view correctly.',
		]],
		['Font Display Optimization', [
			'What it does: adds font-display: swap to local font-face rules when missing and adds display=swap to Google Fonts requests.',
			'Why it helps: text can appear with a fallback font instead of staying invisible while custom fonts download.',
			'Watch for: text may change shape slightly when the real font arrives. That is usually better than invisible text, but check headings and product cards.',
		]],
		['Local Google Fonts Optimization', [
			'What it does: downloads Google Fonts CSS and WOFF2 files into UltraCache storage and rewrites matching frontend Google Fonts links to local files.',
			'Why it helps: font files come from your site instead of a remote Google request, which can reduce connection work and improve privacy.',
			'Watch for: the local font cache is built from the homepage plus any additional scan URLs. If a font appears only on another page, add that URL and rebuild.',
		]],
		['Optimize Self-Hosted Font CSS', [
			'What it does: rewrites local and inline @font-face CSS, adds font-display, prefers matching WOFF2 sources, normalizes font URLs, and can preload likely first-paint fonts.',
			'Why it helps: self-hosted fonts become easier for the browser to discover and less likely to block text.',
			'Watch for: font CSS affects text size and icons. Check headers, menus, product titles, and decorative fonts after enabling.',
		]],
		['Bundle Generated Font-Mix CSS', [
			'What it does: when UltraCache creates several font-mix CSS files, this combines them into one ordered bundle.',
			'Why it helps: one blocking font CSS request is usually better than many blocking font CSS requests.',
			'Watch for: the bundle stays blocking on purpose because font CSS can affect first-paint text and layout.',
		]],
		['Async Generated Font-Mix CSS Bundle', [
			'What it does: async-loads only the single bundle created by Bundle Generated Font-Mix CSS.',
			'Why it helps: a large font-mix bundle can leave the critical path, reducing render-blocking time.',
			'Watch for: text or icons may appear with fallback styling first and settle later. Check the first viewport, menu, product cards, and decorative fonts.',
		]],
		['Delay icon fonts', [
			'What it does: moves only matching @font-face blocks from your visible font pattern list into a delayed, non-blocking font stylesheet.',
			'Why it helps: icon fonts often draw small decorative symbols and do not need to block the whole page.',
			'Watch for: if icons are visible above the fold, they may appear late. Put important text or brand fonts in Never Delay These Fonts.',
		]],
		['Advanced Runtime Font CSS Rewrite', [
			'What it does: watches for late-added local font stylesheet links and rewrites them at runtime.',
			'Why it helps: some builders or plugins inject font CSS after the page starts, and this catches those late links.',
			'Watch for: it uses a browser MutationObserver, so keep it off unless a site specifically needs late font-link rewriting.',
		]],
		['WooCommerce Safe Mode', [
			'What it does: keeps cart, checkout, account, order endpoints, and cart-changing requests away from unsafe public caching.',
			'Why it helps: shop browsing can still be fast while private cart/session pages stay correct.',
			'Watch for: this should usually stay on for WooCommerce stores.',
		]],
		['WooCommerce frontend strategy', [
			'What it does: applies a preset for WooCommerce cart-fragments timing and asset cleanup. Off disables those controls. Safe delays cart fragments only. Balanced also enables general Woo cleanup and Woo Blocks CSS cleanup. Aggressive adds product/gallery cleanup outside product pages and product-filter cleanup when no filter is detected. Custom means the individual switches no longer match a preset.',
			'Why it helps: WooCommerce often loads cart and shop helpers early even on pages where the visitor is only reading or browsing.',
			'Watch for: always test homepage, shop/category, product, cart, checkout, account, add-to-cart, mini-cart, search, and filters after changing this.',
		]],
		['Cart fragments behavior', [
			'What it does: controls WooCommerce cart-fragments on safe anonymous pages. It can leave them alone, delay the request, or suppress empty-cart execution.',
			'Why it helps: cart fragments can create an early wc-ajax request that competes with the first page load.',
			'Watch for: active cart, checkout, account, logged-in, and session-cookie contexts keep normal WooCommerce behavior.',
		]],
		['WooCommerce release timer', [
			'What it does: chooses when the delayed WooCommerce cart-fragments helper releases the request.',
			'Why it helps: using the shared Delayed JS timer keeps Woo timing aligned with the rest of the delayed queue.',
			'Watch for: a longer timer can improve first-load timing but makes the mini-cart refresh later.',
		]],
		['Enable WooCommerce asset cleanup', [
			'What it does: removes selected unnecessary WooCommerce frontend assets from cached HTML and late WordPress queues.',
			'Why it helps: catalog and content pages avoid loading shop scripts/styles that are not needed there.',
			'Watch for: this is the master switch for the Woo cleanup choices below. Test shop, product, cart, checkout, account, filters, and header search.',
		]],
		['Clean WooCommerce product/gallery assets outside product pages', [
			'What it does: removes zoom, flexslider, PhotoSwipe, variation, and single-product assets when the cached HTML is not a single product page.',
			'Why it helps: non-product pages do not need product gallery machinery.',
			'Watch for: if a theme shows product galleries in custom places, add an Asset Cleanup Exclusion or turn this off.',
		]],
		['Clean product filter assets when no filter is detected', [
			'What it does: removes filter scripts and styles when UltraCache cannot find filter markup in the generated HTML.',
			'Why it helps: pages without product filters avoid loading filter code.',
			'Watch for: if filters are injected late or hidden until interaction, UltraCache may not see them. Exclude the filter asset or disable this cleanup.',
		]],
		['Clean WooCommerce Blocks CSS when no Woo blocks are detected', [
			'What it does: removes Woo Blocks CSS when the cached HTML does not contain WooCommerce block markup.',
			'Why it helps: many classic-theme pages load Woo Blocks CSS even when no block needs it.',
			'Watch for: if blocks are injected later by a builder or shortcode, check the page before keeping this on.',
		]],
		['Lazy MailerLite nonce refresh', [
			'What it does: stops MailerLite forms from calling WordPress Ajax on page load just to create a nonce.',
			'Why it helps: the page can load first, then refresh the nonce on first form interaction or before submit.',
			'Watch for: test MailerLite forms after enabling, especially submit behavior after a page has been open for a while.',
		]],
		['Main Thread Relief', [
			'What it does: releases delayed scripts gradually during browser idle time instead of dumping the whole queue at once.',
			'Why it helps: the browser gets smaller bites of JavaScript work, which can reduce long tasks and TBT.',
			'Watch for: some delayed widgets may initialize a little later because UltraCache is pacing them.',
		]],
		['Critical Request Chain Relief', [
			'What it does: preloads resources you list and delays selected non-critical chained assets.',
			'Why it helps: the browser gets a shorter, clearer path to the files that matter for the first view.',
			'Watch for: only list resources you understand from Lighthouse or diagnostics. Delaying the wrong chain item can break visible behavior.',
		]],
		['Fix sliders / hero sections', [
			'What it does: when slider or hero markup is detected, UltraCache protects risky slider/runtime assets and uses safer LCP handling for SR7/Revolution-style first slides.',
			'Why it helps: sliders are often the LCP element and can be broken by normal CSS, JS, or image rewrites.',
			'Watch for: this is a safety feature, not a magic slider optimizer. Still test the first slide, arrows, autoplay, mobile layout, and lazy images.',
		]],
		['Enable Debug', [
			'What it does: allows request-triggered UltraCache debug/source headers when the matching debug request header is sent.',
			'Why it helps: you can inspect cache decisions without showing debug output to normal visitors.',
			'Watch for: keep it off on production unless you are actively debugging.',
		]],
		['Additional URLs for Google Fonts scanning', [
			'What it does: adds extra local pages for the Google Fonts local-cache builder to scan.',
			'Why it helps: fonts that appear only on shop, category, product, or special pages can be discovered and localized.',
			'Watch for: these scans run from admin/save or manual rebuild, not live visitor requests. Use one local URL per line.',
		]],
		['Enable query-string args caching', [
			'What it does: allows UltraCache to cache public URL variants that include query-string arguments.',
			'Why it helps: filter or taxonomy URLs can become cache hits instead of rebuilding every time.',
			'Watch for: excluded query args always bypass cache. The whitelist must contain every query key; an empty whitelist bypasses query-string caching.',
		]],
		['Query-string args whitelist', [
			'What it does: lists query keys that are allowed to create cacheable public variants.',
			'Why it helps: safe filters like product attributes or taxonomy queries can be cached without opening the door to every random query string.',
			'Watch for: one key per line. If a URL has any query key not on the whitelist, it will not be cached.',
		]],
		['Excluded query-string args from Caching', [
			'What it does: lists query keys that always bypass public page cache.',
			'Why it helps: preview, add-to-cart, wc-ajax, search actions, and private actions do not become public cached HTML.',
			'Watch for: this list wins over the whitelist. Keep unsafe dynamic query args here.',
		]],
		['Exclude Paths From Caching', [
			'What it does: lists paths that UltraCache should never store or serve from public HTML cache.',
			'Why it helps: private or changing pages like cart, checkout, account, admin-like flows, or special forms stay live.',
			'Watch for: one path fragment per line. Broad fragments are powerful, so prefer the smallest path that protects the page.',
		]],
		['Safe Tracking Cookies', [
			'What it does: lists cookie names or fragments that may be ignored for public cache decisions.',
			'Why it helps: analytics and marketing IDs do not force a cache bypass when they do not change the page HTML.',
			'Watch for: never put cart, login, pricing, wishlist, compare, checkout, comment, or membership cookies here.',
		]],
		['Never Cache When These Cookies Exist', [
			'What it does: lists cookie names or fragments that force public cache bypass.',
			'Why it helps: visitors with cart, account, session, price, wishlist, compare, checkout, protected-content, or comment state get live HTML.',
			'Watch for: this is one of the most important safety lists. When unsure about a cookie, put it here before making it safe.',
		]],
		['Asset Cleanup Exclusions', [
			'What it does: protects matching handles, URLs, or HTML fragments from WooCommerce asset cleanup.',
			'Why it helps: builders, search widgets, carts, checkout helpers, filters, and custom widgets can keep their needed assets.',
			'Watch for: this affects asset cleanup only. It does not protect JavaScript from defer/delay or CSS from async rules.',
		]],
		['Manual LCP selector', [
			'What it does: gives UltraCache a hint for the main above-the-fold hero or LCP target.',
			'Why it helps: if automatic detection chooses the wrong image or block, this points the optimizer at the right thing.',
			'Watch for: CSS selectors scope discovery to a block. Image URL fragments become manual LCP preload targets.',
		]],
		['Priority Preloads', [
			'What it does: lists important resources that should be discovered early. Lines can start with image, style, script, font, or fetch.',
			'Why it helps: the browser can start fetching a known important file before it would normally discover it.',
			'Watch for: preloading too much creates traffic jams. Use it for real critical resources found in diagnostics.',
		]],
		['Delay Non-Critical Request Chains', [
			'What it does: delays matching local scripts and async-loads matching stylesheets as part of Critical Request Chain Relief.',
			'Why it helps: non-critical chain items stop stretching the path to the first view.',
			'Watch for: only add assets that are not needed immediately. If a UI feature breaks, remove the line or protect the asset elsewhere.',
		]],
		['CSS Bundle Exclusions', [
			'What it does: keeps matching CSS out of UltraCache CSS bundles.',
			'Why it helps: order-sensitive, layout-critical, or troublesome stylesheets can stay exactly where the theme or plugin printed them.',
			'Watch for: this does not automatically exclude the CSS from Async CSS. Use the async exclusion list for that separate timing control.',
		]],
		['Async CSS Exclude List', [
			'What it does: keeps matching stylesheets in the normal blocking CSS flow.',
			'Why it helps: first-view or layout-critical CSS remains available before the browser paints.',
			'Watch for: this protects against both normal Async Remaining CSS and Aggressive Async CSS.',
		]],
		['Delay These Fonts / Icon Font', [
			'What it does: lists font-family names, filenames, or URL fragments whose @font-face blocks may move into delayed font CSS.',
			'Why it helps: decorative icon fonts can stop blocking the first render.',
			'Watch for: use this mostly for icon fonts. Do not delay body text or brand fonts that are visible immediately.',
		]],
		['Never async these external CSS URLs / patterns', [
			'What it does: protects matching external stylesheets from async loading.',
			'Why it helps: third-party CSS that is needed for the first view stays render-blocking on purpose.',
			'Watch for: use domains, filenames, or clear fragments. This does not exclude same-site CSS from normal async rules.',
		]],
		['Never Delay These Fonts / Patterns', [
			'What it does: protects matching fonts from delayed icon-font handling.',
			'Why it helps: important text and brand fonts stay in the normal CSS flow.',
			'Watch for: put visible heading, menu, body, product-title, and logo fonts here if delayed fonts cause late changes.',
		]],
		['Safe Third-Party Delay Patterns', [
			'What it does: lists third-party script fragments that UltraCache may treat as safe to delay, such as analytics and tracking.',
			'Why it helps: those scripts often do not need to run before the visitor sees the page.',
			'Watch for: these are matching patterns for scripts already printed by the site. They do not add new scripts.',
		]],
		['Known Functional Third-Party Delay Patterns', [
			'What it does: lists third-party widget fragments that may be delayed, such as consent, captcha, maps, chat, booking, forms, popups, newsletters, and reviews.',
			'Why it helps: widgets can wait until the delayed queue releases instead of blocking the first view.',
			'Watch for: functional widgets are more fragile than analytics. If a user-facing feature must work immediately, protect it.',
		]],
		['Enable Object Cache', [
			'What it does: installs and uses the WordPress object-cache.php drop-in with the backend you choose.',
			'Why it helps: WordPress can reuse expensive database results and computed objects during requests that still reach PHP.',
			'Watch for: Redis needs correct connection settings. APCu is local memory for one server. SQLite is persistent local storage. Disk is mainly for debugging or constrained hosts.',
		]],
		['Object Cache Fallback', [
			'What it does: chooses what UltraCache tries when the selected object-cache backend cannot be used.',
			'Why it helps: the site can keep running with APCu, SQLite, Disk, or runtime-only cache instead of failing hard.',
			'Watch for: fallback is a safety net, not a performance plan. If fallback is active often, fix the primary backend.',
		]],
		['Redis host', [
			'What it does: tells UltraCache where the Redis server lives.',
			'Why it helps: the object cache can store and fetch shared cache entries from Redis.',
			'Watch for: 127.0.0.1 is common for same-server Redis. External Redis should be intentional and network-safe.',
		]],
		['Redis port', [
			'What it does: tells UltraCache which Redis port to connect to.',
			'Why it helps: Redis only answers on its configured port, commonly 6379.',
			'Watch for: wrong ports make the connection fail and may activate fallback.',
		]],
		['Redis username', [
			'What it does: sends a Redis ACL username when your Redis server requires one.',
			'Why it helps: managed Redis services often use ACL users instead of only a password.',
			'Watch for: leave it blank for older/simple Redis setups that do not use usernames.',
		]],
		['Redis password', [
			'What it does: stores or keeps the Redis secret used to authenticate the object-cache backend.',
			'Why it helps: Redis can be protected while UltraCache still connects.',
			'Watch for: the current saved password is never displayed. Leaving the field blank keeps the current managed password unless you explicitly remove it.',
		]],
		['Redis database', [
			'What it does: chooses the Redis logical database number.',
			'Why it helps: it separates this site from other Redis data when your host uses database numbers.',
			'Watch for: some Redis services allow only database 0. Changing databases can make old cache entries invisible until rebuilt.',
		]],
		['Redis prefix / namespace', [
			'What it does: adds a prefix to cache keys created by this site.',
			'Why it helps: multiple sites can share one Redis server without mixing their object-cache entries.',
			'Watch for: changing the prefix is like starting with an empty object cache.',
		]],
		['Use TLS', [
			'What it does: connects to Redis using TLS when the Redis service expects encrypted transport.',
			'Why it helps: it protects Redis traffic when Redis is not only local/private plain TCP.',
			'Watch for: enabling TLS against a non-TLS Redis endpoint will fail the connection.',
		]],
		['Persistent connection', [
			'What it does: lets PHP reuse Redis connections between requests when the Redis extension supports it.',
			'Why it helps: fewer connection handshakes can reduce overhead on busy sites.',
			'Watch for: persistent connections depend on PHP-FPM and host behavior. If Redis acts odd after config changes, test with this off.',
		]],
		['Connect timeout (ms)', [
			'What it does: sets how long PHP waits while opening the Redis connection.',
			'Why it helps: a dead Redis server should not freeze the page for too long.',
			'Watch for: too low can fail on slow networks. Too high can make outages feel slow.',
		]],
		['Read timeout (ms)', [
			'What it does: sets how long PHP waits for Redis to answer a read.',
			'Why it helps: slow Redis reads do not trap WordPress for too long.',
			'Watch for: too low can cause false failures under load. Too high can slow pages when Redis is unhealthy.',
		]],
		['Purge mode', [
			'What it does: chooses how UltraCache talks to Varnish: an HTTP purge listener or the admin-secret interface.',
			'Why it helps: hosts expose Varnish purge in different ways, and UltraCache needs to use the one your server supports.',
			'Watch for: HTTP mode should point at a real Varnish listener, not your normal public WordPress frontend.',
		]],
		['HTTP endpoints', [
			'What it does: lists Varnish HTTP listener endpoints that can receive BAN or PURGE requests.',
			'Why it helps: Flush All Cache can also clear Varnish when page cache is purged.',
			'Watch for: public frontend ports like domain.com:443 are blocked because they are usually not safe purge listeners.',
		]],
		['Admin endpoints', [
			'What it does: lists Varnish admin socket endpoints in host:port format.',
			'Why it helps: admin-secret mode can purge Varnish without needing an HTTP purge listener.',
			'Watch for: admin mode is safest on local or private endpoints protected by firewall and secret.',
		]],
		['HTTP token / control key', [
			'What it does: stores the token used by the Varnish HTTP purge endpoint when your setup requires one.',
			'Why it helps: the purge endpoint can reject random visitors but allow UltraCache.',
			'Watch for: the saved secret is not displayed. Keep this aligned with your server config.',
		]],
		['Admin secret', [
			'What it does: stores the shared secret for Varnish admin-secret mode.',
			'Why it helps: UltraCache can prove it is allowed to send purge commands to the Varnish admin interface.',
			'Watch for: admin-secret mode uses sensitive server access. Use local/private endpoints when possible.',
		]],
		['Command type', [
			'What it does: chooses BAN or PURGE for HTTP-mode Varnish flushing. Admin mode effectively uses BAN.',
			'Why it helps: different Varnish configs understand different purge commands.',
			'Watch for: BAN is usually safer unless your Varnish setup explicitly requires PURGE.',
		]],
		['Timeout (seconds)', [
			'What it does: sets how long UltraCache waits for each Varnish endpoint.',
			'Why it helps: a slow or unreachable cache layer should not hold up the dashboard for too long.',
			'Watch for: too low can fail on slow networks. Too high makes failed flushes feel stuck.',
		]],
		['Include APCu Flush on Scheduled Cache Cleanup', [
			'What it does: clears APCu user cache when scheduled cache cleanup runs.',
			'Why it helps: APCu entries can be refreshed along with UltraCache generated files.',
			'Watch for: this clears the whole APCu user cache for that PHP runtime, including entries other plugins or apps may use.',
		]],
		['Also flush OPcache', [
			'What it does: includes PHP OPcache reset when Flush All Cache runs.',
			'Why it helps: after code changes, PHP can stop using old compiled script memory.',
			'Watch for: OPcache is about PHP code, not page HTML. Flushing it too often can cause a short warm-up cost.',
		]],
		['Also flush APCu', [
			'What it does: includes APCu user-cache clearing when Flush All Cache runs.',
			'Why it helps: local memory cache entries refresh together with page cache.',
			'Watch for: APCu can be shared by other plugins in the same PHP runtime. If APCu is selected as the object cache backend, this inclusion is forced on.',
		]],
		['Also flush LiteSpeed Cache', [
			'What it does: also asks LiteSpeed/OpenLiteSpeed cache to purge when Flush All Cache runs.',
			'Why it helps: the server cache and UltraCache do not disagree about old pages.',
			'Watch for: UltraCache uses the LiteSpeed plugin API when present, otherwise the server-level purge header.',
		]],
		['Also flush Nginx Cache', [
			'What it does: also calls the detected Nginx helper purge hook when Flush All Cache runs.',
			'Why it helps: Nginx cache does not keep serving old HTML after UltraCache is cleared.',
			'Watch for: this appears only when UltraCache sees a safe Nginx flush mechanism.',
		]],
		['Also flush Varnish Cache', [
			'What it does: also flushes the configured UltraCache Varnish endpoint when Flush All Cache runs.',
			'Why it helps: Varnish and UltraCache clear together instead of leaving stale outer-cache pages.',
			'Watch for: enable and test Varnish integration first. If Varnish is detected but not flushable, fix that before including it.',
		]],
		['Menu warm-up', [
			'What it does: chooses a saved WordPress menu as the URL source for menu warm-up.',
			'Why it helps: important navigation pages can be cached before visitors reach them.',
			'Watch for: only selected menu URLs are warmed. Choose the menu visitors actually use.',
		]],
		['Menu depth', [
			'What it does: decides how deep UltraCache follows the selected menu.',
			'Why it helps: top-level warming is light, while all-depth warming covers more pages.',
			'Watch for: deeper menus create more work. Use a limit that matches the server.',
		]],
		['Full-site warm-up sources', [
			'What it does: chooses which site URL sources feed full-site and scheduled warm-up.',
			'Why it helps: UltraCache can prebuild more cache files after purges or cleanup.',
			'Watch for: source counts can be large. The Scheduled / Cron warm limit still controls how much work runs.',
		]],
		['Scheduled Cache Cleanup', [
			'What it does: runs an automatic full cache purge on the interval you set.',
			'Why it helps: old generated files and stale cache are cleaned without manual work.',
			'Watch for: a purge creates cold cache until warm-up rebuilds it. Pair with cron warm-up when possible.',
		]],
		['Cron Warm Up', [
			'What it does: runs a minute-by-minute background queue that warms HTML and, when configured, missing CSS bundles.',
			'Why it helps: cache is rebuilt gradually instead of making visitors wait after a purge.',
			'Watch for: lower pages-per-minute values are safer on slower servers.',
		]],
		['Start Cron Warm Up after Scheduled Cleanup', [
			'What it does: starts the cron warm queue after scheduled cleanup purges cache.',
			'Why it helps: the site refills cache automatically after the scheduled emptying.',
			'Watch for: requires both scheduled cleanup and cron warm-up to be enabled.',
		]],
		['Start Cron Warm Up after Flush All Cache', [
			'What it does: starts the cron warm queue after a manual full cache purge.',
			'Why it helps: the site begins rebuilding cache right after you clear it.',
			'Watch for: this can create immediate background traffic after pressing Flush All Cache.',
		]],
		['Cleanup interval (hours)', [
			'What it does: sets how often scheduled cache cleanup runs.',
			'Why it helps: you control how often old generated output is cleared.',
			'Watch for: shorter intervals mean more purges and more warm-up work. Longer intervals keep cache around longer.',
		]],
		['Cron warm pages per minute', [
			'What it does: sets how many URLs the cron warm queue processes each minute.',
			'Why it helps: it throttles background warming so the server is not hit too hard.',
			'Watch for: set 0 to pause processing. Higher numbers warm faster but use more CPU and network.',
		]],
		['Scheduled / Cron warm limit', [
			'What it does: caps how many URLs scheduled or cron warm-up may process.',
			'Why it helps: very large sites do not accidentally warm thousands of pages at once.',
			'Watch for: if the limit is lower than your important URL count, some pages stay cold until visited.',
		]],
		['Stale While Revalidate', [
			'What it does: serves stale HTML inside the allowed window while UltraCache refreshes it in the background.',
			'Why it helps: visitors can still get a fast response instead of waiting for a rebuild.',
			'Watch for: stale means old. Use sensible Fresh TTL and Max stale values for stores or frequently changing pages.',
		]],
		['Fresh TTL (minutes)', [
			'What it does: sets how long a cached page counts as fresh.',
			'Why it helps: fresh hits are simple and fast because no background refresh is needed.',
			'Watch for: shorter freshness means more refresh work. Longer freshness keeps old HTML longer.',
		]],
		['Max stale window (minutes)', [
			'What it does: sets how long UltraCache may still serve stale HTML while refreshing in the background.',
			'Why it helps: stale cache can protect visitors from slow rebuilds after freshness expires.',
			'Watch for: do not make this longer than the site content can tolerate.',
		]],
		['CSS bundle cleanup grace window (hours)', [
			'What it does: keeps orphan-like generated CSS bundle files safe for this many hours before cleanup may delete them.',
			'Why it helps: old HTML in Varnish, browser cache, or page cache may still reference those CSS files for a while.',
			'Watch for: shorter windows clean faster but risk missing CSS for stale cached HTML.',
		]],
		['CSS bundle cleanup delete limit', [
			'What it does: limits how many orphan-like CSS bundle files cleanup deletes in one run.',
			'Why it helps: cleanup stays controlled instead of doing a huge delete job at once.',
			'Watch for: lower values are safer on shared hosting. Higher values clear test leftovers faster.',
		]],
		['URL or path', [
			'What it does: gives the Cache Decision Tester a page to simulate without using your admin cookies.',
			'Why it helps: you can see why a page would cache, bypass, match a path rule, match a query rule, or hit a WooCommerce rule.',
			'Watch for: use the real path or full local URL that you want to understand.',
		]],
		['Uninstall cleanup policy', [
			'What it does: chooses what UltraCache removes if the plugin is deleted.',
			'Why it helps: you can keep settings for reinstall testing or remove generated data when you are done.',
			'Watch for: delete-everything is permanent for UltraCache data. It should not delete original media, themes, plugins, or user content.',
		]],
	].forEach((entry) => addOptionHelp(entry[0], entry[1]));

	function getSpecificOptionHelpText(labelText) {
		const key = normalizeOptionHelpKey(labelText);
		if (OPTION_SPECIFIC_HELP[key]) {
			return OPTION_SPECIFIC_HELP[key];
		}
		if (key.indexOf('browser cache headers') !== -1) {
			return OPTION_SPECIFIC_HELP['browser cache headers'];
		}
		if (key.indexOf('apache static html delivery') !== -1) {
			return OPTION_SPECIFIC_HELP['apache static html delivery'];
		}
		return '';
	}

	function getOptionHelpText(label, description, tooltip) {
		const explicit = String(tooltip || '').trim();
		if (explicit) {
			return explicit;
		}

		const shortText = String(description || '').trim();
		if (!shortText) {
			return '';
		}

		const labelText = String(label || '').replace(/\s+/g, ' ').trim();
		const specific = getSpecificOptionHelpText(labelText);
		if (specific) {
			return specific;
		}

		const haystack = (labelText + ' ' + shortText).toLowerCase();
		const notes = [
			'What it does: ' + shortText,
		];

		if (haystack.includes('apache static html')) {
			notes.push('Speed impact: lets Apache serve already-built anonymous HTML cache files before PHP starts, which can remove WordPress bootstrap time on repeat visits.');
			notes.push('Safety model: this is intentionally separate from Browser Cache Headers because it changes page delivery. The generated .htaccess rules allow only plain GET requests with no query string, skip WordPress/WooCommerce/auth cookies, and skip cart, checkout, account, admin, REST, login, preview, and AJAX-style paths.');
			notes.push('Tradeoff: PHP hit counters, PHP debug headers, and PHP-side stale validation do not run for these server-level hits. Use this only after normal page cache is stable.');
		} else if (haystack.includes('browser cache')) {
			notes.push('Speed impact: tells browsers to reuse static assets on later page views instead of re-downloading them. This helps repeat visits and can reduce Lighthouse cache warnings.');
			notes.push('Scope: this writes Apache .htaccess rules for static assets such as CSS, JS, images, fonts, manifests, AVIF/AVIFS, WASM, audio, and video. HTML and generic JSON/XML are deliberately given revalidation-style expiry, not immutable long cache.');
			notes.push('Server note: this affects Apache-compatible .htaccess hosts. Nginx, LiteSpeed server config, and CDN edge rules may need their own configuration.');
		} else if (haystack.includes('defer') || haystack.includes('delay') || haystack.includes('javascript') || haystack.includes(' js')) {
			notes.push('Speed impact: JavaScript timing controls can reduce parser blocking, main-thread work, and total blocking time when non-critical scripts move later.');
			notes.push('Compatibility note: scripts that provide globals or jQuery plugins for other scripts may need Defer Instead of Delay or Do Not Defer or Delay. Use Browser Scanner and Console Error Handler to build visible fixes from real errors.');
		} else if (haystack.includes('css') || haystack.includes('font')) {
			notes.push('Speed impact: CSS and font controls target render-blocking requests, font-display behavior, and request count. They can improve FCP/LCP timing when the resulting layout remains stable.');
			notes.push('Compatibility note: layout-critical CSS should stay blocking. If a page shifts, loses styling, or changes above-the-fold rendering, back off the aggressive option or add a visible exclusion.');
		} else if (haystack.includes('woocommerce') || haystack.includes('cart') || haystack.includes('checkout')) {
			notes.push('Speed impact: WooCommerce controls try to keep catalog and anonymous cacheable pages fast while avoiding dynamic cart, checkout, account, and session-sensitive behavior.');
			notes.push('Safety note: always test homepage, shop/category, product, cart, checkout, account, add-to-cart, and header mini-cart/search after changing this.');
		} else if (haystack.includes('media') || haystack.includes('image') || haystack.includes('webp') || haystack.includes('avif')) {
			notes.push('Speed impact: media controls reduce image transfer size and can improve LCP when the hero image is converted, preloaded, or prioritized correctly.');
			notes.push('Compatibility note: keep the original media available and test browsers that do not support the newest format. UltraCache should fall back rather than breaking images.');
		} else if (haystack.includes('object cache') || haystack.includes('redis') || haystack.includes('apcu')) {
			notes.push('Speed impact: object cache reduces repeated database work during WordPress requests. It helps cache misses, admin requests, WooCommerce, and any page that still reaches PHP.');
			notes.push('Safety note: verify connection/read-write tests before saving Redis changes. Wrong credentials or prefixes can make the site slower or unstable.');
		} else if (haystack.includes('warm') || haystack.includes('pre-render') || haystack.includes('preload')) {
			notes.push('Speed impact: warming builds cache before visitors need it, reducing cold misses after purges, saves, or scheduled cleanup.');
			notes.push('Resource note: larger warm jobs use server CPU/network. Keep rates conservative on shared or busy servers.');
		} else if (haystack.includes('purge') || haystack.includes('cleanup') || haystack.includes('delete')) {
			notes.push('Safety note: cleanup and purge options remove cached or generated files. They should never delete original media, theme files, plugin files, or user content unless the option explicitly says so.');
			notes.push('Operational note: after a purge, pair this with warm-up if you want visitors to avoid cold cache generation.');
		} else if (haystack.includes('varnish') || haystack.includes('nginx') || haystack.includes('litespeed') || haystack.includes('opcache')) {
			notes.push('Speed impact: external cache/runtime integrations can serve or prepare responses before WordPress does. They are powerful but depend on the host configuration.');
			notes.push('Safety note: UltraCache only flushes layers it can detect or safely target. If a host-level cache remains stale, verify the server/plugin integration outside WordPress too.');
		} else if (haystack.includes('exclude') || haystack.includes('allowlist') || haystack.includes('list') || haystack.includes('pattern')) {
			notes.push('How to use: enter one handle, URL fragment, path, query key, cookie name, or pattern per line as described by the field. More specific entries are safer than broad fragments.');
			notes.push('Debugging note: visible lists are the control surface. Prefer scanner suggestions and real browser errors over guessing.');
		} else {
			notes.push('Speed impact: this setting changes how UltraCache prepares, stores, serves, or cleans optimized output. The safest workflow is to change one group of settings, purge/warm if needed, then test the main user paths.');
			notes.push('Compatibility note: if behavior changes unexpectedly, disable this option first or use the related visible exclusion/safeguard fields.');
		}

		return notes.join('\n\n');
	}


	admin.define('help', {
		renderLabelWithHelp,
		getOptionHelpText,
	});
})(window);
