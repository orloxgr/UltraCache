/* UltraCache Admin - Reusable UI primitives */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function' || typeof admin.get !== 'function') {
		throw new Error('UltraCache admin namespace was not loaded.');
	}

	const core = admin.get('core');
	if (!core) {
		throw new Error('UltraCache admin core module was not loaded before ui.js.');
	}

	const {
		h,
		useEffect,
		useRef,
		useState,
		__,
		classNames,
		formatNumber,
	} = core;

	const runtime = {
		getOptionHelpText: (_label, description, tooltip) => tooltip || description || '',
		renderLabelWithHelp: (label) => label,
		mergeUniqueSettingLines: (currentValue, additions) => ({
			value: [String(currentValue || '').trim(), String(additions || '').trim()].filter(Boolean).join('\n'),
		}),
		splitWarmSourceList: (value) => String(value || '').split(/[\s,]+/).map((item) => item.trim()).filter(Boolean),
		joinWarmSourceList: (items) => (Array.isArray(items) ? items : []).join('\n'),
	};

	const SUPPORT_LINKS = {
		coffee: 'https://www.paypal.com/ncp/payment/LDBFB3RRB3E9J',
		beer: 'https://www.paypal.com/ncp/payment/G5RNTC3UF58VU',
		meal: 'https://www.paypal.com/ncp/payment/4NP9RNUYRFRFA',
		hire: 'mailto:byron@iniotakis.com?subject=Hire%20me%20for%20WordPress%20work',
		feature: 'mailto:byron@iniotakis.com?subject=UltraCache%20feature%20request',
		bug: 'mailto:byron@iniotakis.com?subject=UltraCache%20bug%20report',
	};

	function configure(nextRuntime) {
		if (!nextRuntime || typeof nextRuntime !== 'object') {
			return;
		}
		Object.keys(runtime).forEach((key) => {
			if (typeof nextRuntime[key] === 'function') {
				runtime[key] = nextRuntime[key];
			}
		});
	}

	function getOptionHelpText(...args) {
		return runtime.getOptionHelpText(...args);
	}

	function renderLabelWithHelp(...args) {
		return runtime.renderLabelWithHelp(...args);
	}

	function mergeUniqueSettingLines(...args) {
		return runtime.mergeUniqueSettingLines(...args);
	}

	function splitWarmSourceList(...args) {
		return runtime.splitWarmSourceList(...args);
	}

	function joinWarmSourceList(...args) {
		return runtime.joinWarmSourceList(...args);
	}

	function ToastViewport({ toasts, onDismiss }) {
		if (!Array.isArray(toasts) || !toasts.length) {
			return null;
		}

		return h('div', { className: 'uc-toast-viewport' },
			toasts.map((toast) => {
				const tone = toast && toast.type ? toast.type : 'info';
				const actions = toast && Array.isArray(toast.actions) ? toast.actions : [];
				return h('div', { className: classNames('uc-toast', 'uc-toast--' + tone), key: toast.id || toast.text }, [
					h('div', { className: 'uc-toast__body', key: 'body' }, [
						toast.title ? h('div', { className: 'uc-toast__title', key: 'title' }, toast.title) : null,
						h('div', { className: 'uc-toast__text', key: 'text' }, toast.text || ''),
						actions.length ? h('div', { className: 'uc-toast__actions', key: 'actions' }, actions.map((action, index) => h('button', {
							type: 'button',
							className: classNames('uc-toast__action', action && action.variant === 'danger' ? 'uc-toast__action--danger' : ''),
							onClick: () => {
								if (action && typeof action.onClick === 'function') {
									action.onClick(toast);
								}
							},
							key: 'action-' + index,
						}, action && action.label ? action.label : 'Action'))) : null,
					]),
					h('button', {
						type: 'button',
						className: 'uc-toast__close',
						onClick: () => onDismiss(toast.id),
						'aria-label': __("Dismiss notification", 'ultracache'),
						key: 'close',
					}, '×'),
				]);
			})
		);
	}

	function SupportActionLinks({ compact, onHireClick }) {
		const items = [
			{ key: 'hire', label: __("Hire me", 'ultracache'), href: SUPPORT_LINKS.hire, onClick: onHireClick },
			{ key: 'feature', label: __("Feature request", 'ultracache'), href: SUPPORT_LINKS.feature },
			{ key: 'bug', label: __("Bug report", 'ultracache'), href: SUPPORT_LINKS.bug },
		];

		return h('div', { className: classNames('uc-support-links', 'uc-support-links--actions', compact ? 'uc-support-links--compact' : '') },
			items.map((item) => h('a', {
				key: item.key,
				className: classNames('uc-support-link', 'uc-support-link--hire'),
				href: item.href,
				onClick: item.key === 'hire' && typeof item.onClick === 'function' ? item.onClick : undefined,
			}, [
				h('span', { className: 'uc-support-link__label', key: 'label' }, item.label),
				h('span', { className: 'uc-support-link__amount', key: 'amount' }, __("Email", 'ultracache')),
			]))
		);
	}

	function SupportLinks({ compact, onHireClick }) {
		const items = [
			{ key: 'coffee', label: __("Buy me a coffee", 'ultracache'), amount: '€5', href: SUPPORT_LINKS.coffee, kind: 'paypal' },
			{ key: 'beer', label: __("Buy me a beer", 'ultracache'), amount: '€10', href: SUPPORT_LINKS.beer, kind: 'paypal' },
			{ key: 'meal', label: __("Buy me a meal", 'ultracache'), amount: '€15', href: SUPPORT_LINKS.meal, kind: 'paypal' },
		];

		return h('div', { className: classNames('uc-support-links', compact ? 'uc-support-links--compact' : '') },
			items.map((item) => h(
				'a',
				{
					key: item.key,
					className: classNames('uc-support-link', 'uc-support-link--paypal'),
					href: item.href,
					target: item.kind === 'paypal' ? '_blank' : undefined,
					rel: item.kind === 'paypal' ? 'noopener noreferrer' : undefined,
					onClick: item.kind === 'hire' && typeof onHireClick === 'function' ? onHireClick : undefined,
				},
				[
					h('span', { className: 'uc-support-link__label', key: 'label' }, item.label),
					h('span', { className: 'uc-support-link__amount', key: 'amount' }, item.amount),
				]
			))
		);
	}

	function SupportInlineCard({ isMobile, onMobileTrigger, onHireClick }) {
		const triggerProps = isMobile
			? {
				role: 'button',
				tabIndex: 0,
				onClick: onMobileTrigger,
				onKeyDown: (event) => {
					if ('Enter' === event.key || ' ' === event.key) {
						event.preventDefault();
						onMobileTrigger();
					}
				},
			}
			: {};

		return h('div', { className: classNames('uc-support-inline', isMobile ? 'uc-support-inline--mobile' : '' ) }, [
			h('div', { className: 'uc-support-inline__copy', key: 'copy' }, [
				h('div', Object.assign({ className: 'uc-support-inline__title' }, triggerProps), __("Support this plugin", 'ultracache')),
				!isMobile ? h('p', { className: 'uc-support-inline__text' }, __("If UltraCache saves you time, you can support future development or reach out for help.", 'ultracache')) : null,
			]),
			h('div', { className: 'uc-support-inline__actions', key: 'actions' }, [
				h('div', { className: 'uc-support-inline__support-group', key: 'support-group' }, [
					h('div', { className: 'uc-support-inline__group-label' }, __("Support this plugin", 'ultracache')),
					h(SupportLinks, { compact: isMobile, key: 'paypal-links' }),
					h('div', { className: 'uc-support-inline__need-support', key: 'need-support' }, [
						h('div', { className: 'uc-support-inline__group-label', key: 'need-label' }, __("Need Support?", 'ultracache')),
						h(SupportActionLinks, { compact: isMobile, key: 'support-actions', onHireClick }),
				]),
			]),
		]),
		]);
	}

	function SupportModal({ open, isMobile, onClose, onHireClick }) {
		useEffect(() => {
			if (!open) {
				return undefined;
			}
			const handleKeyDown = (event) => {
				if ('Escape' === event.key) {
					onClose();
				}
			};
			window.addEventListener('keydown', handleKeyDown);
			return () => window.removeEventListener('keydown', handleKeyDown);
		}, [open, onClose]);

		if (!open) {
			return null;
		}

		const titleId = 'uc-support-modal-title';
		const descriptionId = 'uc-support-modal-description';

		return h('div', {
			className: classNames('uc-support-modal', isMobile ? 'uc-support-modal--mobile' : 'uc-support-modal--desktop'),
			onClick: onClose,
			role: 'presentation',
		}, [
			h('div', {
				className: 'uc-support-modal__dialog',
				onClick: (event) => event.stopPropagation(),
				role: 'dialog',
				'aria-modal': 'true',
				'aria-labelledby': titleId,
				'aria-describedby': descriptionId,
				key: 'dialog',
			}, [
				h('button', {
					type: 'button',
					className: 'uc-support-modal__close',
					onClick: onClose,
					'aria-label': __("Close support modal", 'ultracache'),
					key: 'close',
				}, '×'),
				h('div', { className: 'uc-support-modal__eyebrow', key: 'eyebrow' }, __("Support this plugin", 'ultracache')),
				h('h3', { className: 'uc-support-modal__title', id: titleId, key: 'title' }, __("Support this plugin", 'ultracache')),
				h('p', { className: 'uc-support-modal__text', id: descriptionId, key: 'text' }, __("If UltraCache saves you time, you can support future updates or contact Byron directly for paid help.", 'ultracache')),
				h('div', { className: 'uc-support-modal__section-label', key: 'support-label' }, __("Support this plugin", 'ultracache')),
				h(SupportLinks, { compact: isMobile, onHireClick, key: 'links' }),
					h('div', { className: 'uc-support-modal__need-support', key: 'need-support' }, [
						h('div', { className: 'uc-support-modal__section-label', key: 'need-label' }, __("Need Support?", 'ultracache')),
						h(SupportActionLinks, { compact: isMobile, onHireClick, key: 'support-actions' }),
					]),
				]),
		]);
	}


	const VERSION_HELP_FAQ_SECTIONS = [
		{
			key: "page-cache",
			title: __("Page Cache & Delivery", 'ultracache'),
			items: [
				{
					question: __("Is the WordPress admin area cached?", 'ultracache'),
					answer: __("UltraCache does not page-cache WordPress administration pages under /wp-admin/. When Object Cache is enabled, WordPress admin requests still benefit from cached database results, options, and reusable WordPress objects. This can significantly improve dashboard, editor, and plugin-management performance while every admin page remains dynamic.", 'ultracache'),
				},
				{
					question: __("Are logged-in users cached?", 'ultracache'),
					answer: __("Logged-in users bypass the public page cache by default. They can still benefit from Object Cache on the frontend and inside the WordPress admin area.", 'ultracache'),
				},
				{
					question: __("Are WooCommerce cart, checkout, and account pages cached?", 'ultracache'),
					answer: __("No. Dynamic WooCommerce pages such as Cart, Checkout, and My Account are excluded from shared public page caching.", 'ultracache'),
				},
				{
					question: __("Does UltraCache cache URLs with query strings?", 'ultracache'),
					answer: __("Only when every query key is explicitly permitted by the configured query-string allowlist. When the allowlist is empty, query-string requests bypass page cache.", 'ultracache'),
				},
				{
					question: __("Can UltraCache be used with another page-cache plugin?", 'ultracache'),
					answer: __("Two page-cache systems should not manage the same site at the same time. They may compete for rewrite rules, cache files, and the WordPress advanced-cache.php drop-in. Disable the other page-cache layer before enabling UltraCache page caching.", 'ultracache'),
				},
				{
					question: __("What does Apache Static HTML Delivery do?", 'ultracache'),
					answer: __("On compatible Apache configurations, it lets the web server deliver eligible cached HTML directly through .htaccess without loading WordPress or PHP for each cached request.", 'ultracache'),
				},
				{
					question: __("Does UltraCache compress HTML?", 'ultracache'),
					answer: __("UltraCache can handle HTML compression when the server is not already doing it. Use the HTML Compression check under Cache Engine to detect whether compression is already provided by Apache, Nginx, LiteSpeed, a CDN, or another server layer.", 'ultracache'),
				},
				{
					question: __("What does Flush All Cache remove?", 'ultracache'),
					answer: __("It clears the UltraCache page cache, flushes the active UltraCache Object Cache, invalidates rebuildable frontend cache data, and can also purge selected external/server cache layers when those options are enabled. It does not delete WordPress content, original Media Library files, generated AVIF/WebP images, or the local Google Fonts cache.", 'ultracache'),
				},
				{
					question: __("Should I flush the cache after editing a post or page?", 'ultracache'),
					answer: __("Normally no. When Warm affected pages after save is enabled, UltraCache clears and warms the related URLs automatically. Use Flush All Cache after broader changes such as theme updates, global CSS or JavaScript changes, plugin changes that alter frontend output, or major optimization-setting changes.", 'ultracache'),
				},
				{
					question: __("Why can the first uncached visit be slower?", 'ultracache'),
					answer: __("The first eligible request after a purge may need to generate the page HTML and related frontend cache data. Warm-up prepares selected URLs before normal visitors reach them.", 'ultracache'),
				},
			],
		},
		{
			key: "warm-up",
			title: __("Warm-up & Automation", 'ultracache'),
			items: [
				{
					question: __("What is cache warm-up?", 'ultracache'),
					answer: __("Cache warm-up visits selected public URLs and creates their cache before a normal visitor opens them.", 'ultracache'),
				},
				{
					question: __("Which menu warm-up depth should I use?", 'ultracache'),
					answer: __("The first menu level suits most websites because it normally contains the most important pages without creating an unnecessarily large queue. Greater depth can add hundreds or thousands of URLs.", 'ultracache'),
				},
				{
					question: __("Which full-site warm-up sources should I select?", 'ultracache'),
					answer: __("Homepage / blog index, Selected menu URLs, Pages, Posts, and Categories cover most websites. Add other post types, archives, or taxonomies only when those public URLs are useful to visitors.", 'ultracache'),
				},
				{
					question: __("Does full-site warm-up process every URL immediately?", 'ultracache'),
					answer: __("No. URLs are added to a controlled queue and processed according to the warm-up settings and available server resources.", 'ultracache'),
				},
				{
					question: __("What does Warm affected pages after save do?", 'ultracache'),
					answer: __("When content changes, UltraCache identifies related URLs, removes their previous page cache, and schedules those URLs to be warmed again instead of rebuilding the entire site.", 'ultracache'),
				},
				{
					question: __("What is Cron Warm Up?", 'ultracache'),
					answer: __("Cron Warm Up periodically prepares the selected URLs in the background. Start Cron Warm Up after Scheduled Cleanup begins a new warm-up after scheduled cache cleanup finishes.", 'ultracache'),
				},
				{
					question: __("Does cache warm-up also convert images?", 'ultracache'),
					answer: __("No. Page warm-up and image conversion are separate operations. Use AVIF / WebP Batch Conversion to prepare existing Media Library images.", 'ultracache'),
				},
				{
					question: __("Can a very large warm-up selection take a long time?", 'ultracache'),
					answer: __("Yes. Deep menus, many custom post types, and large archive or taxonomy selections can create a substantial queue. Select the URL sources that visitors actually use.", 'ultracache'),
				},
			],
		},
		{
			key: "frontend",
			title: __("CSS, JavaScript & Fonts", 'ultracache'),
			items: [
				{
					question: __("What is the difference between Defer and Delay JavaScript?", 'ultracache'),
					answer: __("Defer downloads a script during page loading but executes it after the HTML has been parsed. Delay postpones execution until the configured trigger, such as interaction or timing.", 'ultracache'),
				},
				{
					question: __("What are Defer parallel execution and Delay parallel execution?", 'ultracache'),
					answer: __("They control parallel execution separately for the Defer and Delay script groups. They are not first-party and third-party switches.", 'ultracache'),
				},
				{
					question: __("What should I do if a menu, slider, form, or popup stops working?", 'ultracache'),
					answer: __("Identify the affected script and add the relevant handle or URL pattern to the appropriate exclusion or compatibility list. There is normally no need to disable all JavaScript optimization.", 'ultracache'),
				},
				{
					question: __("Does UltraCache combine every stylesheet into one global file?", 'ultracache'),
					answer: __("No. UltraCache can build optimized CSS bundles and keep page-specific CSS separate where appropriate, so one page does not need to load CSS that belongs only to another page.", 'ultracache'),
				},
				{
					question: __("When should I rebuild Separate CSS Bundles?", 'ultracache'),
					answer: __("Rebuild them after changes to global styling, theme output, page-builder CSS, or CSS optimization settings. A normal text edit does not usually require a complete rebuild.", 'ultracache'),
				},
				{
					question: __("What does Local Google Fonts Optimization do?", 'ultracache'),
					answer: __("It downloads supported Google Fonts from the site configuration to the WordPress server and serves local copies instead of requiring visitor browsers to retrieve them from Google.", 'ultracache'),
				},
				{
					question: __("What does Bundle Generated Font-Mix CSS do?", 'ultracache'),
					answer: __("It combines generated local-font declarations and related font CSS into an optimized local resource.", 'ultracache'),
				},
				{
					question: __("What does Delay icon fonts do?", 'ultracache'),
					answer: __("It postpones icon-font styles that are not required for the first visible render, including supported Font Awesome, Elementor, Material Icons, Swiper, and theme-specific icon fonts.", 'ultracache'),
				},
			],
		},
		{
			key: "lcp",
			title: __("LCP Optimization", 'ultracache'),
			items: [
				{
					question: __("What is LCP?", 'ultracache'),
					answer: __("Largest Contentful Paint is the largest visible content element rendered during the initial page load. It can be an image, heading, text block, video poster, CSS background, or slider background.", 'ultracache'),
				},
				{
					question: __("Does UltraCache assume that the LCP element is always an image?", 'ultracache'),
					answer: __("No. UltraCache can learn text, images, posters, and background images. It creates an image preload only when the confirmed LCP actually uses an image resource.", 'ultracache'),
				},
				{
					question: __("How does automatic LCP detection work?", 'ultracache'),
					answer: __("When LCP Frontend Discovery is enabled and no manual selector is configured, UltraCache observes the actual browser LCP on eligible cacheable pages without query parameters. The first result is used immediately, and the mapping locks when the same candidate appears in two of the last three visits.", 'ultracache'),
				},
				{
					question: __("Why does automatic LCP detection require more than one observation?", 'ultracache'),
					answer: __("The first observation is used immediately. A two-of-three rolling confirmation then locks the result and stops discovery for that page and viewport, reducing the chance that a temporary slider or timing variation becomes permanent.", 'ultracache'),
				},
				{
					question: __("Are LCP mappings stored separately by viewport?", 'ultracache'),
					answer: __("Yes. A page can have different confirmed LCP mappings for different viewport classes, such as a heading on a smaller screen and a background image on a larger screen.", 'ultracache'),
				},
				{
					question: __("What happens when I configure a manual LCP selector?", 'ultracache'),
					answer: __("Manual selectors take precedence over automatic LCP learning.", 'ultracache'),
				},
				{
					question: __("Does LCP learning require Lighthouse?", 'ultracache'),
					answer: __("No. LCP Frontend Discovery learns from normal eligible visits while it is enabled. Lighthouse or PageSpeed can be used afterward to measure the result.", 'ultracache'),
				},
				{
					question: __("What happens when the confirmed LCP is text?", 'ultracache'),
					answer: __("UltraCache records the text element and does not add an unrelated image preload for that mapping.", 'ultracache'),
				},
				{
					question: __("What is shown in LCP Diagnostics & Settings?", 'ultracache'),
					answer: __("LCP Diagnostics & Settings loads ten discovered URLs only after the accordion opens. Full viewport, selector, resource, confirmation, and refresh details are requested only after you select a URL.", 'ultracache'),
				},
				{
					question: __("What does Relearn do?", 'ultracache'),
					answer: __("Relearn keeps the current mapping active as a fallback, clears its confirmation evidence, and allows new eligible visits to learn and lock a replacement for the same viewport.", 'ultracache'),
				},
				{
					question: __("What does Forget mapping do?", 'ultracache'),
					answer: __("It removes the stored mapping and refreshes the page cache without it. The next eligible visit can start learning a replacement while LCP Frontend Discovery is active.", 'ultracache'),
				},
				{
					question: __("Why is an LCP refresh pending?", 'ultracache'),
					answer: __("The mapping has been confirmed, but the affected page is still waiting for its page cache to be rebuilt by the warm queue.", 'ultracache'),
				},
				{
					question: __("Do logged-in administrator visits create automatic LCP observations?", 'ultracache'),
					answer: __("When administrator-only discovery is enabled, only logged-in administrators contribute. Otherwise anonymous public visits and full administrators can contribute while the configured discovery period is active.", 'ultracache'),
				},
			],
		},
		{
			key: "media",
			title: __("Images & Media Library", 'ultracache'),
			items: [
				{
					question: __("What is the difference between AVIF and WebP?", 'ultracache'),
					answer: __("AVIF usually produces smaller files. WebP provides broader compatibility with older software, image tools, and some hosting environments.", 'ultracache'),
				},
				{
					question: __("Can AVIF be used with a WebP fallback?", 'ultracache'),
					answer: __("Yes. A common configuration is AVIF as the primary format and WebP as the fallback.", 'ultracache'),
				},
				{
					question: __("Should I test AVIF before using it?", 'ultracache'),
					answer: __("Yes. Run Image conversion test and then Check test. This verifies the server encoder and lets you compare the generated results, including transparency when suitable PNG files are available.", 'ultracache'),
				},
				{
					question: __("What image compression level should I use?", 'ultracache'),
					answer: __("Compact suits most normal website images and usually provides a strong file-size reduction with little visible difference. Photography, artwork, or detailed product imagery may need a higher-quality setting.", 'ultracache'),
				},
				{
					question: __("What does Maximum upload image side control?", 'ultracache'),
					answer: __("It limits the largest width or height of newly uploaded raster images. A value of 1920 suits most websites; increase it when the site genuinely needs larger source images.", 'ultracache'),
				},
				{
					question: __("Are SVG files converted to AVIF or WebP?", 'ultracache'),
					answer: __("No. SVG is a vector format and remains SVG.", 'ultracache'),
				},
				{
					question: __("What does Convert new uploads do?", 'ultracache'),
					answer: __("It prepares newly uploaded raster images using the selected primary format, fallback format, maximum side, and image compression level.", 'ultracache'),
				},
				{
					question: __("Does changing image quality rebuild existing optimized files?", 'ultracache'),
					answer: __("Not by itself. Enable Regen. existing and run the conversion process when existing AVIF or WebP files should be recreated with the current quality.", 'ultracache'),
				},
				{
					question: __("What does Start / Resume Conversion do?", 'ultracache'),
					answer: __("It processes existing Media Library images through the AVIF/WebP batch queue and continues an interrupted conversion from its saved progress.", 'ultracache'),
				},
				{
					question: __("What does Rebuild / Repair Media Queue do?", 'ultracache'),
					answer: __("It reconstructs the media-processing queue from the current Media Library and output policy. It is used when queue state is missing or inconsistent.", 'ultracache'),
				},
				{
					question: __("What does Recount Optimized Image Files do?", 'ultracache'),
					answer: __("It recalculates the dashboard counts for existing optimized image files. It does not create new images.", 'ultracache'),
				},
				{
					question: __("What does Clear Completed Queue Rows do?", 'ultracache'),
					answer: __("It removes completed records from the media queue. It does not delete the generated AVIF/WebP files.", 'ultracache'),
				},
				{
					question: __("What is the difference between Image Optimization and Media Library Replacement?", 'ultracache'),
					answer: __("Image Optimization creates alternative AVIF/WebP files while retaining the normal Media Library structure. Media Library Replacement is a separate advanced workflow that can promote verified converted files into attachment metadata, generated sizes, database references, and supported active-theme CSS references.", 'ultracache'),
				},
				{
					question: __("Does Media Library Replacement delete originals immediately?", 'ultracache'),
					answer: __("No. The workflow separates Check, Prepare, Do, Verify, and Delete Originals. Original cleanup remains unavailable until the replacement files and supported references have been verified.", 'ultracache'),
				},
				{
					question: __("Can Media Library Replacement continue after an interrupted request?", 'ultracache'),
					answer: __("Yes. Its long-running stages persist progress and can pause, resume, or retry.", 'ultracache'),
				},
			],
		},
		{
			key: "object-cache",
			title: __("Object Cache & Drop-ins", 'ultracache'),
			items: [
				{
					question: __("What is the difference between Page Cache and Object Cache?", 'ultracache'),
					answer: __("Page Cache stores completed HTML for public pages. Object Cache stores reusable WordPress data such as query results, options, and calculated objects. Object Cache can improve both frontend and wp-admin requests.", 'ultracache'),
				},
				{
					question: __("Which Object Cache backend should I use?", 'ultracache'),
					answer: __("Use the backend that is correctly available on the server. APCu is very fast local shared memory, Redis is persistent and can be shared between processes or servers, and SQLite is a disk-based alternative when APCu or Redis is unavailable. Disk is intended for advanced or diagnostic use.", 'ultracache'),
				},
				{
					question: __("Is APCu always better than Redis?", 'ultracache'),
					answer: __("APCu is often faster on a single PHP server because it uses local shared memory, but its data does not survive PHP or server restarts. Redis is persistent and can support multi-process or multi-server deployments.", 'ultracache'),
				},
				{
					question: __("Is SQLite Object Cache suitable for every website?", 'ultracache'),
					answer: __("SQLite is useful when Redis or APCu is unavailable, but its performance depends on storage speed and server concurrency.", 'ultracache'),
				},
				{
					question: __("Why does UltraCache create advanced-cache.php?", 'ultracache'),
					answer: __("WordPress loads the advanced-cache.php drop-in early so UltraCache can serve eligible page-cache responses before normal WordPress page generation.", 'ultracache'),
				},
				{
					question: __("Why does UltraCache create object-cache.php?", 'ultracache'),
					answer: __("WordPress uses the object-cache.php drop-in to replace the default runtime object cache with the selected persistent or advanced UltraCache backend.", 'ultracache'),
				},
				{
					question: __("What happens if another plugin leaves a conflicting cache drop-in?", 'ultracache'),
					answer: __("UltraCache detects conflicting advanced-cache.php and object-cache.php files. When an administrator explicitly chooses Remove, UltraCache deletes the selected conflicting drop-in without creating a backup.", 'ultracache'),
				},
				{
					question: __("Does Flush All Cache also flush Object Cache?", 'ultracache'),
					answer: __("Yes. Flush All Cache clears the active UltraCache Object Cache as part of the full purge.", 'ultracache'),
				},
			],
		},
		{
			key: "integrations",
			title: __("WooCommerce & Integrations", 'ultracache'),
			items: [
				{
					question: __("What does Suppress empty-cart execution do?", 'ultracache'),
					answer: __("It reduces unnecessary WooCommerce cart processing when the visitor has no cart contents.", 'ultracache'),
				},
				{
					question: __("Will UltraCache cache customer-specific WooCommerce data?", 'ultracache'),
					answer: __("No. Customer-specific cart, checkout, account, and other excluded dynamic requests do not use the shared public page cache.", 'ultracache'),
				},
				{
					question: __("What does Lazy MailerLite nonce refresh do?", 'ultracache'),
					answer: __("It postpones MailerLite nonce refresh work so it does not unnecessarily affect the initial cached page response.", 'ultracache'),
				},
				{
					question: __("Does UltraCache work with TranslatePress?", 'ultracache'),
					answer: __("Yes. UltraCache can vary cached pages by the active TranslatePress language so translated URLs do not share the wrong HTML cache.", 'ultracache'),
				},
				{
					question: __("Can UltraCache purge Varnish?", 'ultracache'),
					answer: __("Yes. Configure and test the Varnish connection and purge details in the Varnish Cache section.", 'ultracache'),
				},
				{
					question: __("Can Varnish and UltraCache Page Cache be used together?", 'ultracache'),
					answer: __("Yes. UltraCache can manage its WordPress page cache while Varnish acts as an additional delivery layer, provided the Varnish purge integration is configured so both layers are invalidated together.", 'ultracache'),
				},
				{
					question: __("Do third-party script matching rules load or contact those services?", 'ultracache'),
					answer: __("No. Matching rules only identify scripts already added by the site, theme, or another plugin so UltraCache can apply delay, defer, or exclusion behavior.", 'ultracache'),
				},
			],
		},
		{
			key: "diagnostics",
			title: __("Diagnostics, Storage & Support", 'ultracache'),
			items: [
				{
					question: __("Why does the dashboard show zero page-cache files?", 'ultracache'),
					answer: __("The cache may have just been flushed, no eligible anonymous public page may have been loaded yet, the requested page may be excluded, or the warm-up queue may not have completed.", 'ultracache'),
				},
				{
					question: __("Should I test public caching while logged in?", 'ultracache'),
					answer: __("Use an anonymous or incognito window for public Page Cache testing. LCP Frontend Discovery can learn from anonymous visits while public discovery is active, or only from administrators when the administrator-only option is enabled.", 'ultracache'),
				},
				{
					question: __("Does clearing browser site data also clear UltraCache?", 'ultracache'),
					answer: __("No. Browser storage and server-side UltraCache data are separate. Use Flush All Cache to clear the server-side caches managed by that action.", 'ultracache'),
				},
				{
					question: __("What should I check after changing optimization settings?", 'ultracache'),
					answer: __("Open the main public pages and confirm that navigation, sliders, forms, product/cart actions, and page layout work normally and that no new browser-console errors appear.", 'ultracache'),
				},
				{
					question: __("Do I need to run Lighthouse after every normal content edit?", 'ultracache'),
					answer: __("No. Lighthouse is most useful after initial setup or changes to the theme, optimization configuration, or major frontend components.", 'ultracache'),
				},
				{
					question: __("Can long-running jobs be resumed?", 'ultracache'),
					answer: __("Supported batch operations use resumable queues. Use Start / Resume instead of recreating the entire job after an interruption.", 'ultracache'),
				},
				{
					question: __("What is the difference between cache counters and the actual cache state?", 'ultracache'),
					answer: __("Dashboard counters summarize stored files and queue records. Recount or rebuild tools refresh those summaries when files or queue rows have changed outside the normal workflow.", 'ultracache'),
				},
				{
					question: __("Where can I get help?", 'ultracache'),
					answer: __("Open Help for the installed version inside the UltraCache dashboard. For additional support, use the UltraCache support forum at https://wordpress.org/support/plugin/ultracache/.", 'ultracache'),
				},
				{
					question: __("Where are cache files stored?", 'ultracache'),
					answer: __("Page-cache files are stored in ultracache/cache/ below the active WordPress uploads directory. Object-cache storage and generated optimization assets use dedicated directories below the same uploads/ultracache/ root.", 'ultracache'),
				},
				{
					question: __("What happens when UltraCache is deactivated or deleted?", 'ultracache'),
					answer: __("The standard WordPress Plugins screen shows an UltraCache deactivation dialog where an administrator selects what should be retained if the plugin is later deleted. Available policies can retain settings and custom tables or remove plugin runtime/cache data. Converted media files are retained by design.", 'ultracache'),
				},
				{
					question: __("Does UltraCache send visitor data to an UltraCache-owned service?", 'ultracache'),
					answer: __("No. UltraCache stores cache files, generated assets, settings, queue records, and diagnostics locally on the WordPress installation. Optional integrations contact only the services explicitly configured or opened by an administrator.", 'ultracache'),
				},
			],
		},
	];


	function VersionHelpModal({ open, version, onClose }) {
		const closeButtonRef = useRef(null);

		useEffect(() => {
			if (!open) {
				return undefined;
			}

			const handleKeyDown = (event) => {
				if ('Escape' === event.key) {
					onClose();
				}
			};

			window.addEventListener('keydown', handleKeyDown);
			window.setTimeout(() => {
				if (closeButtonRef.current && typeof closeButtonRef.current.focus === 'function') {
					closeButtonRef.current.focus();
				}
			}, 0);

			return () => window.removeEventListener('keydown', handleKeyDown);
		}, [open, onClose]);

		if (!open) {
			return null;
		}

		const currentVersion = String(version || '').trim();
		const titleId = 'uc-version-help-modal-title';
		const descriptionId = 'uc-version-help-modal-description';

		return h('div', {
			className: 'uc-version-help-modal',
			onClick: onClose,
			role: 'presentation',
		}, [
			h('div', {
				className: 'uc-version-help-modal__dialog',
				id: 'uc-version-help-modal',
				onClick: (event) => event.stopPropagation(),
				role: 'dialog',
				'aria-modal': 'true',
				'aria-labelledby': titleId,
				'aria-describedby': descriptionId,
				key: 'dialog',
			}, [
				h('button', {
					type: 'button',
					className: 'uc-support-modal__close',
					onClick: onClose,
					ref: closeButtonRef,
					'aria-label': __('Close UltraCache help', 'ultracache'),
					key: 'close',
				}, '×'),
				h('div', { className: 'uc-support-modal__eyebrow', key: 'eyebrow' }, currentVersion ? ('UltraCache ' + currentVersion) : 'UltraCache'),
				h('h3', { className: 'uc-support-modal__title', id: titleId, key: 'title' }, currentVersion ? ('Help for ' + currentVersion) : __('UltraCache help', 'ultracache')),
				h('p', { className: 'uc-version-help-modal__intro', id: descriptionId, key: 'intro' }, __('Recommended setup for most WordPress websites. Apply the profile, complete the site-specific selections below, then prepare the cache and images.', 'ultracache')),
				h('ol', { className: 'uc-version-help-modal__steps', key: 'steps' }, [
					h('li', { key: 'profile' }, [h('strong', null, __('Activate the Aggressive profile.', 'ultracache')), ' ', __('It enables the main performance options, Warm affected pages after save, and Apache Static HTML Delivery.', 'ultracache')]),
					h('li', { key: 'compression' }, [h('strong', null, __('Check HTML Compression.', 'ultracache')), ' ', __('Open the dropdown under HTML Compression in Cache Engine and run the check so UltraCache can use the server result.', 'ultracache')]),
					h('li', { key: 'menu-warm' }, [h('strong', null, __('Select the main menu for warm-up.', 'ultracache')), ' ', __('In Warm Cache, choose the primary frontend menu and the required depth. First-level menu URLs suit most websites and prepare the pages visitors are most likely to open.', 'ultracache')]),
					h('li', { key: 'full-warm' }, [h('strong', null, __('Choose Full-site warm-up sources.', 'ultracache')), ' ', __('Homepage / blog index, Selected menu URLs, Pages, Posts, and Categories are suitable for most sites. Additional sources can add many URLs to the queue.', 'ultracache')]),
					h('li', { key: 'uploads' }, [h('strong', null, __('Enable Convert new uploads.', 'ultracache')), ' ', __('New Media Library uploads will use the selected image conversion policy automatically.', 'ultracache')]),
					h('li', { key: 'max-side' }, [h('strong', null, __('Set Maximum upload image side.', 'ultracache')), ' ', __('1920 pixels is appropriate for most modern websites. Increase it only when larger source images are required.', 'ultracache')]),
					h('li', { key: 'format' }, [h('strong', null, __('Choose the image formats.', 'ultracache')), ' ', __('AVIF provides excellent compression, while WebP offers broader compatibility. AVIF with WebP fallback is a strong general-purpose configuration.', 'ultracache')]),
					h('li', { key: 'compression-level' }, [h('strong', null, __('Choose Image compression level.', 'ultracache')), ' ', __('Compact provides a strong reduction in file size with little visible difference for normal website images.', 'ultracache')]),
					h('li', { key: 'image-test' }, [h('strong', null, __('Test AVIF before the first batch.', 'ultracache')), ' ', __('Run Image conversion test and then Check test. UltraCache uses a Media Library sample and includes transparent PNG files when available.', 'ultracache')]),
					h('li', { key: 'fonts' }, [h('strong', null, __('Enable the main font optimizations.', 'ultracache')), ' ', __('Turn on Local Google Fonts Optimization, Bundle Generated Font-Mix CSS, and Delay icon fonts.', 'ultracache')]),
					h('li', { key: 'woo' }, [h('strong', null, __('For WooCommerce, enable Suppress empty-cart execution.', 'ultracache')), ' ', __('This removes unnecessary empty-cart frontend work.', 'ultracache')]),
					h('li', { key: 'mailerlite' }, [h('strong', null, __('For MailerLite, enable Lazy MailerLite nonce refresh.', 'ultracache')), ' ', __('The option is available in Advanced Settings.', 'ultracache')]),
					h('li', { key: 'object-cache' }, [h('strong', null, __('Confirm the detected Object Cache backend.', 'ultracache')), ' ', __('APCu is usually the fastest local option but is not persistent across PHP restarts. Redis is persistent. SQLite is available when neither is present, with performance depending on disk speed.', 'ultracache')]),
					h('li', { key: 'varnish' }, [h('strong', null, __('Configure Varnish when present.', 'ultracache')), ' ', __('Enter the required connection and purge details in the Varnish Cache box.', 'ultracache')]),
					h('li', { key: 'automation' }, [h('strong', null, __('Enable scheduled warm-up.', 'ultracache')), ' ', __('In Automation & Scheduling, turn on Cron Warm Up and Start Cron Warm Up after Scheduled Cleanup.', 'ultracache')]),
				]),
				h('div', { className: 'uc-version-help-modal__section', key: 'first-run' }, [
					h('h4', { className: 'uc-version-help-modal__section-title', key: 'title' }, __('Prepare the website after saving', 'ultracache')),
					h('ul', { className: 'uc-version-help-modal__actions', key: 'actions' }, [
						h('li', { key: 'flush' }, __('Run Flush All Cache to clear previous cached output.', 'ultracache')),
						h('li', { key: 'menu' }, __('Run Warm Up Menu HTML Cache + Separate CSS Bundles to prepare the main pages immediately.', 'ultracache')),
						h('li', { key: 'media' }, __('Run Start / Resume Conversion in AVIF / WebP Batch Conversion to prepare existing Media Library images.', 'ultracache')),
						h('li', { key: 'full' }, __('Use Full-site warm-up when the complete selected URL set should be cached.', 'ultracache')),
					]),
				]),
				h('div', { className: 'uc-version-help-modal__section', key: 'post-install-check' }, [
					h('h4', { className: 'uc-version-help-modal__section-title', key: 'title' }, __('Must-do post-install JavaScript check', 'ultracache')),
					h('p', { className: 'uc-version-help-modal__section-copy', key: 'intro' }, __('Run this check after the first setup and after major JavaScript optimization changes. Use a private browser window so you test the public cached page rather than a logged-in administration session.', 'ultracache')),
					h('ol', { className: 'uc-version-help-modal__steps', key: 'steps' }, [
						h('li', { key: 'private-window' }, [h('strong', null, __('Open the website in a private window.', 'ultracache')), ' ', __('Use Ctrl+Shift+N in Chrome or Edge, or Ctrl+Shift+P in Firefox.', 'ultracache')]),
						h('li', { key: 'console' }, [h('strong', null, __('Open the browser Console.', 'ultracache')), ' ', __('Press F12, select Console, and reload the page.', 'ultracache')]),
						h('li', { key: 'interact' }, [h('strong', null, __('Check the visible page functions.', 'ultracache')), ' ', __('Open the menu and use the main slider, form, popup, search, cart, or other interactive elements present on that page.', 'ultracache')]),
						h('li', { key: 'copy-errors' }, [h('strong', null, __('Copy any JavaScript errors.', 'ultracache')), ' ', __('If no red JavaScript errors appear, the post-install check is complete. Otherwise copy the full error lines and their stack traces.', 'ultracache')]),
						h('li', { key: 'open-diagnostics' }, [h('strong', null, __('Open JS Defer / Delay Safeguards & Diagnostics.', 'ultracache')), ' ', __('Return to the UltraCache dashboard and expand that accordion.', 'ultracache')]),
						h('li', { key: 'extract' }, [h('strong', null, __('Analyze the copied errors.', 'ultracache')), ' ', __('Paste them into Console Error Handler and click Extract Console Error Suggestions.', 'ultracache')]),
						h('li', { key: 'append' }, [h('strong', null, __('Append the proposed fixes.', 'ultracache')), ' ', __('Use Append to Defer Instead for the normal compatibility fix. Use Append to “Do Not Defer or Delay” for suggestions placed in that fallback group or when the same error remains after the first pass.', 'ultracache')]),
						h('li', { key: 'save' }, [h('strong', null, __('Save the safeguard lists.', 'ultracache')), ' ', __('Click Save Both Lists after appending the required suggestions.', 'ultracache')]),
						h('li', { key: 'flush-warm' }, [h('strong', null, __('Flush and warm the cache again.', 'ultracache')), ' ', __('Run Flush All Cache, then warm the front page. Front-page warm-up is enough during this testing loop.', 'ultracache')]),
						h('li', { key: 'repeat' }, [h('strong', null, __('Repeat until the Console is clear.', 'ultracache')), ' ', __('Reload the anonymous page and repeat the same process until no JavaScript errors remain.', 'ultracache')]),
					]),
				]),

				h('div', { className: 'uc-version-help-modal__section', key: 'faq' }, [
					h('h4', { className: 'uc-version-help-modal__section-title', key: 'title' }, __('Frequently asked questions', 'ultracache')),
					h('p', { className: 'uc-version-help-modal__section-copy', key: 'intro' }, __('Open a topic and then select a question. The FAQ covers the common setup, cache, media, LCP, integration, and troubleshooting questions for the installed plugin.', 'ultracache')),
					h('div', { className: 'uc-version-help-modal__faq', key: 'topics' }, VERSION_HELP_FAQ_SECTIONS.map((section) =>
						h('details', { className: 'uc-version-help-modal__faq-section', key: section.key }, [
							h('summary', { className: 'uc-version-help-modal__faq-section-summary', key: 'summary' }, [
								h('span', { key: 'title' }, section.title),
								h('span', { className: 'uc-version-help-modal__faq-count', key: 'count' }, String(section.items.length)),
							]),
							h('div', { className: 'uc-version-help-modal__faq-items', key: 'items' }, section.items.map((item, itemIndex) =>
								h('details', { className: 'uc-version-help-modal__faq-item', key: section.key + '-' + String(itemIndex) }, [
									h('summary', { className: 'uc-version-help-modal__faq-question', key: 'question' }, item.question),
									h('p', { className: 'uc-version-help-modal__faq-answer', key: 'answer' }, item.answer),
								])
							)),
						])
					)),
				]),
				h('div', { className: 'uc-version-help-modal__note', key: 'note' }, __('After this initial setup and post-install check, UltraCache handles page caching, affected-page warming, scheduled warm-up, and new image conversion according to the saved settings.', 'ultracache')),
				h('div', { className: 'uc-version-help-modal__support', key: 'support' }, [
					h('span', { className: 'uc-version-help-modal__support-text', key: 'text' }, __('Still having questions?', 'ultracache')),
					h('a', {
						className: 'uc-version-help-modal__support-link',
						href: 'https://wordpress.org/support/plugin/ultracache/',
						target: '_blank',
						rel: 'noopener noreferrer',
						key: 'link',
					}, __('Ask on forums', 'ultracache')),
				]),
			]),
		]);
	}

	function Card({ title, description, children, footer }) {
		return h('div', { className: 'uc-card' }, [
			h('div', { className: 'mb-5', key: 'head' }, [
				h('h3', { className: 'text-lg font-bold m-0 text-white', key: 'title' }, title),
				description
					? h(
							'p',
							{ className: 'uc-stat-label mt-1 mb-0', key: 'desc' },
							description
					 )
					: null,
			]),
			h('div', { key: 'body' }, children),
			footer
				? h('div', { className: 'mt-5 pt-4 border-t border-white/5', key: 'footer' }, footer)
				: null,
		]);
	}

	function AccordionBox({ title, description, children, keyName }) {
		return h('details', { className: 'uc-accordion uc-accordion--card', key: keyName || String(title || '') }, [
			h('summary', { className: 'uc-accordion__summary' }, [
				h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
					h('div', { className: 'uc-accordion__title' }, title),
					description ? h('div', { className: 'uc-accordion__description' }, description) : null,
				]),
				h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
			]),
			h('div', { className: 'uc-accordion__body space-y-4' }, children),
		]);
	}

	function SettingsAccordionCard({ title, description, children, keyName }) {
		return h('div', { className: 'uc-card', key: (keyName || String(title || 'settings-accordion')) + '-card' }, [
			AccordionBox({ title, description, children, keyName }),
		]);
	}

	function StatCard({ label, value, hint, action }) {
		return h('div', { className: 'uc-card relative' }, [
			h('div', { className: 'text-xs tracking-widest text-zinc-500 mb-2 pr-8', key: 'label' }, label),
			h('div', { className: 'ultracache-stat-card-value text-3xl font-black tracking-tight text-white pr-8', key: 'value' }, value),
			h('div', { className: 'text-xs text-zinc-500 mt-2 pr-8', key: 'hint' }, hint || '\u00A0'),
			action
				? h('button', {
					type: 'button',
					className: 'uc-stat-card-action',
					title: action.title || '',
					onClick: action.onClick,
					disabled: !!action.disabled,
					key: 'action',
				}, action.label || '+')
				: null,
		]);
	}

	function Button({ onClick, disabled, children, variant }) {
		const styleClass =
			variant === 'primary'
				? 'uc-btn--primary text-white'
				: variant === 'light'
				? 'uc-btn--primary text-white'
				: variant === 'danger'
				? 'uc-btn--danger'
				: '';

		return h(
			'button',
			{
				className: classNames('uc-btn', styleClass, 'disabled:opacity-50 disabled:cursor-not-allowed'),
				onClick,
				disabled,
			},
			children
		);
	}

	function ToggleRow({ label, description, checked, onChange, disabled, tooltip, hideDescription }) {
		const helpText = getOptionHelpText(label, description, tooltip);
		return h('div', { className: 'flex items-center justify-between py-4' }, [
			h('div', { key: 'left' }, [
				h('div', { className: 'text-sm font-medium text-white' }, renderLabelWithHelp(label, helpText)),
				description ? h('div', { className: 'text-xs text-zinc-500' }, description) : null,
			]),
			h(
				'label',
				{
					className: classNames('uc-toggle', disabled ? 'opacity-60 pointer-events-none' : ''),
					key: 'right',
				},
				[
					h('input', {
						type: 'checkbox',
						checked: !!checked,
						disabled: !!disabled,
						onChange: (e) => onChange(e.target.checked),
					}),
					h('span', { className: 'slider' }),
				]
			),
		]);
	}

	function ToggleField({ label, description, checked, onChange, disabled, tooltip }) {
		const helpText = getOptionHelpText(label, description, tooltip);
		return h('div', { className: 'uc-field-wrap' }, [
			h('div', { className: 'flex items-center justify-between gap-4 px-1 py-1' }, [
				h('div', { key: 'left', className: 'min-w-0 flex-1' }, [
					label ? h('div', { className: 'uc-field-label mb-0' }, renderLabelWithHelp(label, helpText)) : null,
					description ? h('div', { className: 'text-xs text-zinc-500 mt-1' }, description) : null,
				]),
				h(
					'label',
					{
						className: classNames('uc-toggle', disabled ? 'opacity-60 pointer-events-none' : ''),
						key: 'right',
					},
					[
						h('input', {
							type: 'checkbox',
							checked: !!checked,
							disabled: !!disabled,
							onChange: (e) => onChange(e.target.checked),
						}),
						h('span', { className: 'slider' }),
					]
				),
			]),
		]);
	}

	function TextAreaField({ label, description, value, onChange, disabled, placeholder, tooltip }) {
		const helpText = getOptionHelpText(label, description, tooltip);
		return h('div', { className: 'uc-field-wrap' }, [
			h('label', { className: 'uc-field-label' }, renderLabelWithHelp(label, helpText)),
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('textarea', {
				className: 'uc-field-input uc-field-textarea',
				value: value || '',
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => onChange(e.target.value),
			}),
		]);
	}

	function SaveableTextAreaField({ label, description, value, onSave, disabled, placeholder, saveLabel, populateLabel, populateBusyLabel, onPopulate, populateWarning, appendRequest, tooltip }) {
		const [draft, setDraft] = useState(value || '');
		const [populateBusy, setPopulateBusy] = useState(false);
		const helpText = getOptionHelpText(label, description, tooltip);

		useEffect(() => {
			setDraft(value || '');
		}, [value]);

		useEffect(() => {
			if (!appendRequest || !appendRequest.id || !appendRequest.value) {
				return;
			}
			setDraft((current) => mergeUniqueSettingLines(String(current || ''), String(appendRequest.value || '')).value);
		}, [appendRequest]);

		const currentValue = String(value || '');
		const draftValue = String(draft || '');
		const hasChanges = draftValue !== currentValue;
		const hasPopulate = typeof onPopulate === 'function';
		const hasDraftContent = draftValue.trim().length > 0;

		async function handlePopulateClick() {
			if (!hasPopulate || populateBusy || disabled) {
				return;
			}

			if (hasDraftContent && typeof window !== 'undefined' && typeof window.confirm === 'function') {
				if (!window.confirm(populateWarning || 'Your current whitelist will be replaced.')) {
					return;
				}
			}

			setPopulateBusy(true);
			try {
				const populatedValue = await onPopulate(draftValue);
				if (typeof populatedValue === 'string') {
					setDraft(populatedValue);
				}
			} finally {
				setPopulateBusy(false);
			}
		}

		return h('div', { className: 'uc-field-wrap' }, [
			h('label', { className: 'uc-field-label' }, renderLabelWithHelp(label, helpText)),
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('textarea', {
				className: 'uc-field-input uc-field-textarea',
				value: draft,
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => setDraft(e.target.value),
			}),
			hasPopulate && hasDraftContent ? h('div', { className: 'mt-2 text-xs text-amber-300 bg-amber-500/10 rounded-xl px-3 py-2' }, populateWarning || 'Your current whitelist will be replaced.') : null,
			h('div', { className: 'mt-3 flex items-center justify-between gap-3' }, [
				hasPopulate ? h(Button, {
					onClick: handlePopulateClick,
					disabled: !!disabled || populateBusy,
				}, populateBusy ? (populateBusyLabel || 'Populating…') : (populateLabel || 'Populate')) : h('span', { 'aria-hidden': 'true' }, ''),
				h(Button, {
					onClick: () => onSave(draftValue),
					disabled: !!disabled || !hasChanges,
					variant: 'primary',
				}, saveLabel || 'Save'),
			]),
		]);
	}

	function NumberField({ label, description, value, onChange, disabled, min, step, tooltip }) {
		const helpText = getOptionHelpText(label, description, tooltip);
		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'uc-field-label' }, renderLabelWithHelp(label, helpText)) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('input', {
				type: 'number',
				className: 'uc-field-input',
				value: value,
				disabled: !!disabled,
				min: typeof min === 'number' ? min : 0,
				step: typeof step === 'number' ? step : 1,
				onChange: (e) => onChange(e.target.value),
			}),
		]);
	}

	function NumberRow({ label, description, value, onChange, disabled, min, max, step, className, tooltip }) {
		const helpText = getOptionHelpText(label, description, tooltip);
		return h('div', { className: classNames('uc-number-row flex items-center justify-between gap-4 py-4', className || '') }, [
			h('div', { key: 'left', className: 'min-w-0 pr-4' }, [
				label ? h('div', { className: 'text-sm font-medium text-white' }, renderLabelWithHelp(label, helpText)) : null,
				description ? h('div', { className: 'text-xs text-zinc-500 mt-1' }, description) : null,
			]),
			h('input', {
				key: 'right',
				type: 'number',
				className: 'uc-field-input uc-number-row-input',
				value: value,
				disabled: !!disabled,
				min: typeof min === 'number' ? min : 0,
				max: typeof max === 'number' ? max : undefined,
				step: typeof step === 'number' ? step : 1,
				onChange: (e) => onChange(e.target.value),
			}),
		]);
	}

	function TextRow({ label, description, value, onChange, disabled, placeholder, type, className, autoComplete, inputMode, tooltip }) {
		const helpText = getOptionHelpText(label, description, tooltip);
		return h('div', { className: classNames('uc-number-row flex items-center justify-between gap-4 py-4', className || '') }, [
			h('div', { key: 'left', className: 'min-w-0 pr-4' }, [
				label ? h('div', { className: 'text-sm font-medium text-white' }, renderLabelWithHelp(label, helpText)) : null,
				description ? h('div', { className: 'text-xs text-zinc-500 mt-1' }, description) : null,
			]),
			h('input', {
				key: 'right',
				type: type || 'text',
				className: 'uc-field-input uc-number-row-input',
				value: value || '',
				disabled: !!disabled,
				placeholder: placeholder || '',
				autoComplete: autoComplete || undefined,
				inputMode: inputMode || undefined,
				onChange: (e) => onChange(e.target.value),
			}),
		]);
	}

	function TextField({ label, description, value, onChange, disabled, placeholder, onKeyDown, type, tooltip }) {
		const helpText = getOptionHelpText(label, description, tooltip);
		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'uc-field-label' }, renderLabelWithHelp(label, helpText)) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('input', {
				type: type || 'text',
				className: 'uc-field-input',
				value: value || '',
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => onChange(e.target.value),
				onKeyDown: onKeyDown,
			}),
		]);
	}

	function SelectField({ label, description, value, onChange, disabled, options, tooltip, hideDescription, id, name, dataSettingKey }) {
		const helpText = getOptionHelpText(label, description, tooltip);
		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'block text-sm font-medium text-white' }, renderLabelWithHelp(label, helpText)) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mt-1 mb-2' }, description) : null,
			h('div', { className: 'uc-select-wrap' }, [
				h('select', {
					className: 'uc-field-input uc-field-select',
					id: id || undefined,
					name: name || undefined,
					'data-ultracache-setting-key': dataSettingKey || undefined,
					value: value || '',
					disabled: !!disabled,
					onChange: (e) => onChange(e.target.value),
				}, (options || []).map((option) => h('option', { value: option.value, disabled: !!option.disabled, title: option.title || undefined, key: option.value }, option.label))),
				h('span', { className: 'uc-select-icon', 'aria-hidden': 'true' }, '▾'),
			]),
		]);
	}

	function CustomSelect({ value, options, onChange, disabled, className, onBeforeOpen, loading, loadingLabel }) {
		const [open, setOpen] = useState(false);
		const [opening, setOpening] = useState(false);
		const wrapRef = useRef(null);
		const selected = (options || []).find((option) => option.value === value) || (options || [])[0] || { value: '', label: '' };
		const isLoading = !!loading || opening;

		useEffect(() => {
			function handleOutsideClick(event) {
				if (!wrapRef.current || wrapRef.current.contains(event.target)) {
					return;
				}
				setOpen(false);
			}

			document.addEventListener('mousedown', handleOutsideClick);
			return () => document.removeEventListener('mousedown', handleOutsideClick);
		}, []);

		function selectOption(option) {
			if (disabled || isLoading || !option || option.disabled) {
				return;
			}
			setOpen(false);
			if (option.value !== value) {
				onChange(option.value);
			}
		}

		async function toggleOpen() {
			if (disabled || isLoading) {
				return;
			}
			if (open) {
				setOpen(false);
				return;
			}
			if (typeof onBeforeOpen === 'function') {
				setOpening(true);
				try {
					const allowed = await onBeforeOpen();
					if (false === allowed) {
						return;
					}
				} finally {
					setOpening(false);
				}
			}
			setOpen(true);
		}

		return h('div', { ref: wrapRef, className: classNames('uc-custom-select', className || '', disabled ? 'opacity-60 pointer-events-none' : '') }, [
			h('button', {
				type: 'button',
				className: 'uc-field-input uc-custom-select-button',
				disabled: !!disabled || isLoading,
				'aria-busy': isLoading ? 'true' : 'false',
				onClick: toggleOpen,
			}, [
				h('span', { key: 'label' }, isLoading ? (loadingLabel || __('Please wait…', 'ultracache')) : selected.label),
				h('span', { key: 'icon', className: 'uc-custom-select-icon', 'aria-hidden': 'true' }, '▾'),
			]),
			open ? h('div', { className: 'uc-custom-select-menu', role: 'listbox' }, (options || []).map((option) => h('button', {
				type: 'button',
				key: option.value,
				disabled: !!option.disabled,
				'aria-disabled': option.disabled ? 'true' : 'false',
				className: classNames('uc-custom-select-option', option.value === value ? 'is-selected' : '', option.disabled ? 'is-disabled' : ''),
				onClick: () => selectOption(option),
			}, option.label))) : null,
		]);
	}

	function MultiSelectField({ label, description, value, onChange, disabled, options, tooltip }) {
		const helpText = getOptionHelpText(label, description, tooltip);
		const selected = splitWarmSourceList(value);
		const availableOptions = Array.isArray(options) ? options : [];
		const selectedMap = {};
		selected.forEach((item) => { selectedMap[item] = true; });

		function toggleValue(nextValue, checked) {
			const cleanValue = String(nextValue || '').trim();
			if (!cleanValue || disabled) {
				return;
			}

			const nextSelected = selected.filter((item) => item !== cleanValue);
			if (checked) {
				nextSelected.push(cleanValue);
			}
			onChange(joinWarmSourceList(nextSelected));
		}

		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'block text-sm font-medium text-white' }, renderLabelWithHelp(label, helpText)) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mt-1 mb-2' }, description) : null,
			h('details', { className: classNames('uc-switch-dropdown', disabled ? 'opacity-60 pointer-events-none' : '') }, [
				h('summary', { className: 'uc-switch-dropdown-summary' }, [
					h('span', { key: 'label' }, selected.length ? (selected.length + ' source' + (selected.length === 1 ? '' : 's') + ' selected') : 'Select full-site sources'),
					h('span', { key: 'icon', className: 'uc-select-icon', 'aria-hidden': 'true' }, '▾'),
				]),
				h('div', { className: 'uc-switch-dropdown-panel' }, availableOptions.length ? availableOptions.map((option) => {
					const optionValue = String(option.value || '');
					const checked = !!selectedMap[optionValue];
					return h('label', { className: 'uc-switch-dropdown-row', key: optionValue }, [
						h('span', { className: 'uc-switch-dropdown-text' }, option.label),
						h('span', { className: classNames('uc-toggle', disabled ? 'opacity-60 pointer-events-none' : '') }, [
							h('input', {
							type: 'checkbox',
							checked: checked,
							disabled: !!disabled,
							onChange: (event) => toggleValue(optionValue, event.target.checked),
						}),
							h('span', { className: 'slider' }),
						]),
					]);
				}) : h('div', { className: 'text-xs text-zinc-500 px-3 py-3' }, __("No sources detected.", 'ultracache'))),
			]),
			h('div', { className: 'text-[11px] text-zinc-500 mt-2' }, selected.length ? ('Selected: ' + selected.length) : 'No sources selected. Full-site warm buttons stay off.'),
		]);
	}

	function DetailRow({ label, value, mono }) {
		if (value === null || typeof value === 'undefined' || value === '') {
			return null;
		}

		return h('div', { className: 'flex flex-col gap-1 py-2' }, [
			h('div', { className: 'text-[11px] tracking-widest text-zinc-500' }, label),
			h('div', { className: classNames('text-sm text-white break-all', mono ? 'font-mono text-[12px]' : '') }, String(value)),
		]);
	}

	function StatusPill({ ok, text, tone }) {
		const variant = tone || (ok ? 'success' : 'neutral');
		const toneClass = 'success' === variant
			? 'text-emerald-400'
			: ('warning' === variant ? 'text-cyan-400' : 'text-zinc-300');
		if ('plain' === variant) {
			return h('span', { className: 'inline-flex items-center justify-end text-right text-xs font-normal tracking-normal text-zinc-400 break-words max-w-xl' }, text);
		}
		return h('span', {
			className: classNames('inline-flex items-center justify-end text-right text-xs font-normal tracking-normal break-words max-w-xl', toneClass),
		}, text);
	}

	function ProgressPanel({ process, etaText, onCancel, showWhenInactive }) {
		const [dismissed, setDismissed] = useState(false);

		useEffect(() => {
			if (process.active) {
				setDismissed(false);
			}
		}, [process.active, process.type, process.label]);

		if ((!process.active && !showWhenInactive) || (!process.active && dismissed)) {
			return null;
		}

		const closeButton = !process.active
			? h('button', {
				type: 'button',
				className: 'uc-process-popup__close',
				onClick: () => setDismissed(true),
				title: __('Close', 'ultracache'),
				'aria-label': __('Close', 'ultracache'),
				key: 'close',
			}, '×')
			: null;

		const safeTotal = Math.max(0, Number(process.total || 0));
		const safeCurrent = safeTotal > 0 ? Math.min(Math.max(0, Number(process.current || 0)), safeTotal) : Math.max(0, Number(process.current || 0));
		const percent = safeTotal > 0 ? Math.min(100, Math.round((safeCurrent / safeTotal) * 100)) : 0;
		const progressText = safeTotal > 0
			? safeCurrent + ' / ' + safeTotal + (process.queueBuilding ? ' (building queue)' : '') + ' (' + percent + '%)'
			: (process.complete ? 'Complete' : (process.queueBuilding ? 'Building queue…' : 'Preparing…'));
		const unitText = process.type === 'media' && Number(process.unitCount || 0) > 0
			? ('Image units checked: ' + Math.max(0, Number(process.unitCount || 0)))
			: '';
		const avifCount = Math.max(0, Number(process.avifCount || 0));
		const webpCount = Math.max(0, Number(process.webpCount || 0));
		const successCount = Math.max(0, Number(process.successCount || 0));
		const skippedCount = Math.max(0, Number(process.skippedCount || 0));
		const failedCount = Math.max(0, Number(process.failedCount || 0));
		const varnishWarmedCount = Math.max(0, Number(process.varnishWarmedCount || 0));
		const varnishVerifiedCount = Math.max(0, Number(process.varnishVerifiedCount || 0));
		const varnishBypassedCount = Math.max(0, Number(process.varnishBypassedCount || 0));
		const varnishInconclusiveCount = Math.max(0, Number(process.varnishInconclusiveCount || 0));
		const hasCounters = successCount > 0 || skippedCount > 0 || failedCount > 0 || avifCount > 0 || webpCount > 0 || varnishWarmedCount > 0 || varnishVerifiedCount > 0 || varnishBypassedCount > 0 || varnishInconclusiveCount > 0 || Number(process.unitCount || 0) > 0;


		return h('div', { className: 'uc-process-popup', role: 'status', 'aria-live': 'polite' }, [
			h('div', { className: 'uc-process-popup__card bg-emerald-500/5 p-6 rounded' }, [
			h('div', { className: 'flex justify-between items-center mb-3 gap-3', key: 'head' }, [
				h(
					'h4',
					{ className: 'text-sm font-bold tracking-widest text-emerald-400 m-0' },
					process.label || 'Working'
				),
				h('div', { className: 'flex items-center gap-3', key: 'controls' }, [
					h('span', { className: 'text-[10px] text-zinc-500', key: 'eta' }, etaText || ''),
					process.cancellable && typeof onCancel === 'function'
						? h(
							'button',
							{
								className: 'uc-btn !bg-zinc-800 !text-white !border-white/10 !py-2 !px-3 text-xs font-bold',
								onClick: onCancel,
								disabled: !!process.cancelRequested,
								key: 'cancel',
							},
							process.cancelRequested ? 'Stopping…' : 'Cancel'
						 )
						: null,
					closeButton,
				]),
			]),
			h('div', { className: 'flex justify-between text-xs mb-2', key: 'meta' }, [
				h('span', { className: 'text-zinc-400' }, __("Overall Progress", 'ultracache')),
				h(
					'span',
					{ className: 'text-emerald-400 font-mono' },
					progressText
				),
			]),
			h('div', { className: 'w-full bg-zinc-800 h-1 rounded-full overflow-hidden', key: 'bar-wrap' }, [
				h('div', {
					className: 'bg-emerald-500 h-full transition-all duration-300',
					style: { width: percent + '%' },
				}),
			]),
			unitText ? h('div', { className: 'mt-2 text-[11px] text-zinc-500', key: 'unit-count' }, unitText) : null,
			hasCounters
				? h('div', {
					className: 'uc-media-operation-counters mt-3 text-[11px]',
					key: 'job-counters',
					style: { display: 'flex', flexWrap: 'wrap', gap: '4px 14px', alignItems: 'center' },
				}, process.type === 'media'
					? [
						h('span', { className: 'text-emerald-400 font-bold', key: 'checked' }, 'Attachments checked: ' + safeCurrent + (safeTotal > 0 ? ' / ' + safeTotal : '')),
						h('span', { className: 'text-zinc-400 font-bold', key: 'units' }, 'Image units checked: ' + Math.max(0, Number(process.unitCount || 0))),
						h('span', { className: avifCount > 0 ? 'text-emerald-400 font-bold' : 'text-zinc-500 font-bold', key: 'avif' }, 'AVIF generated: ' + avifCount),
						h('span', { className: webpCount > 0 ? 'text-emerald-400 font-bold' : 'text-zinc-500 font-bold', key: 'webp' }, 'WebP generated: ' + webpCount),
						h('span', { className: 'text-zinc-400 font-bold', key: 'already' }, 'Already optimized: ' + skippedCount),
						h('span', { className: failedCount > 0 ? 'text-amber-400 font-bold' : 'text-zinc-500 font-bold', key: 'failed' }, 'Failed: ' + failedCount),
					]
					: [
						h('span', { className: 'text-emerald-400 font-bold', key: 'cached' }, 'Cached: ' + successCount),
						h('span', { className: 'text-zinc-400 font-bold', key: 'skipped' }, 'Skipped: ' + skippedCount),
						h('span', { className: failedCount > 0 ? 'text-amber-400 font-bold' : 'text-zinc-500 font-bold', key: 'failed' }, 'Failed: ' + failedCount),
						varnishWarmedCount > 0 ? h('span', { className: 'text-emerald-400 font-bold', key: 'varnish-warmed' }, 'Varnish warmed: ' + varnishWarmedCount) : null,
						varnishVerifiedCount > 0 ? h('span', { className: 'text-emerald-400 font-bold', key: 'varnish-verified' }, 'Varnish HIT verified: ' + varnishVerifiedCount) : null,
						varnishBypassedCount > 0 ? h('span', { className: 'text-amber-400 font-bold', key: 'varnish-bypassed' }, 'Varnish bypassed: ' + varnishBypassedCount) : null,
						varnishInconclusiveCount > 0 ? h('span', { className: 'text-zinc-400 font-bold', key: 'varnish-inconclusive' }, 'Varnish inconclusive: ' + varnishInconclusiveCount) : null,
					].filter(Boolean))
				: null,
			process.logs && process.logs.length
				? h(
						'div',
						{
							className:
								'mt-4 max-h-40 overflow-y-auto text-[11px] font-mono text-zinc-400 bg-black/20 p-3 rounded space-y-1',
							key: 'logs',
						},
						process.logs.slice(-10).map((log, index) =>
							h('div', { key: 'log-' + index }, '> ' + log)
						)
				 )
				: null,
		])
		]);
	}

	admin.define('ui', {
		configure,
		ToastViewport,
		SupportInlineCard,
		SupportModal,
		VersionHelpModal,
		Card,
		SettingsAccordionCard,
		StatCard,
		Button,
		ToggleRow,
		ToggleField,
		TextAreaField,
		SaveableTextAreaField,
		NumberField,
		NumberRow,
		TextRow,
		TextField,
		SelectField,
		CustomSelect,
		MultiSelectField,
		DetailRow,
		StatusPill,
		ProgressPanel,
	});
})(window);
