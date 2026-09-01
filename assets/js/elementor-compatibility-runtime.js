(function () {
	'use strict';

	if (window.__ultracacheElementorCompatibilityRuntimeV1) {
		return;
	}
	window.__ultracacheElementorCompatibilityRuntimeV1 = 1;

	function root() {
		return document.documentElement || document.body || document.head;
	}

	function mark(key, value) {
		try {
			var target = root();
			if (target) {
				target.setAttribute('data-ultracache-delay-' + key, String(value));
			}
		} catch (e) {}
	}

	function afterDomReady(callback, delay) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', function () {
				setTimeout(callback, delay || 0);
			}, { once: true });
			return;
		}
		setTimeout(callback, delay || 0);
	}

	function afterLoad(callback, delay) {
		if (document.readyState === 'complete') {
			setTimeout(callback, delay || 0);
			return;
		}
		window.addEventListener('load', function () {
			setTimeout(callback, delay || 0);
		}, { once: true });
	}

	function hasElementorInlineBg(node) {
		try {
			return !!(node && node.querySelector && node.querySelector('[style*="background-image"],[style*="background:"]'));
		} catch (e) {
			return false;
		}
	}

	var observer = null;
	var observerViewportHeight = 0;

	function revealNode(node) {
		if (!node || !node.classList) {
			return false;
		}
		node.classList.add('e-lazyloaded');
		node.setAttribute('data-ultracache-elementor-bg-lazy-class', '1');
		return true;
	}

	function getObserver(viewportHeight) {
		if (!window.IntersectionObserver) {
			return null;
		}
		if (observer && observerViewportHeight === viewportHeight) {
			return observer;
		}
		if (observer) {
			observer.disconnect();
		}
		observerViewportHeight = viewportHeight;
		observer = new window.IntersectionObserver(function (entries, currentObserver) {
			var revealed = 0;
			for (var i = 0; i < entries.length; i++) {
				var entry = entries[i];
				var rect = entry.boundingClientRect || { top: 0, bottom: 0 };
				if (!entry.isIntersecting || !(rect.top < viewportHeight * 2 && rect.bottom > -viewportHeight)) {
					continue;
				}
				if (revealNode(entry.target)) {
					revealed++;
				}
				currentObserver.unobserve(entry.target);
			}
			if (revealed) {
				mark('elementor-bg-lazy-observer-revealed', revealed);
			}
		}, {
			root: null,
			rootMargin: viewportHeight + 'px 0px ' + viewportHeight + 'px 0px',
			threshold: 0
		});
		return observer;
	}

	function revealElementorLazyBgs() {
		try {
			var viewportHeight = Math.max(window.innerHeight || 0, 600);
			var parents = Array.prototype.slice.call(document.querySelectorAll('.e-con.e-parent:not(.e-lazyloaded):not(.e-no-lazyload)'));
			var checked = 0;
			var revealed = 0;
			var observed = 0;
			var currentObserver = getObserver(viewportHeight);
			var directReveals = [];
			var fallbackReads = [];

			for (var i = 0; i < parents.length && checked < 80; i++) {
				var node = parents[i];
				checked++;
				if (!hasElementorInlineBg(node)) {
					continue;
				}
				if (i < 3) {
					if (currentObserver) {
						currentObserver.unobserve(node);
						if (revealNode(node)) {
							revealed++;
						}
					} else {
						directReveals.push(node);
					}
					continue;
				}
				if (currentObserver) {
					currentObserver.observe(node);
					observed++;
					continue;
				}
				var rect = node.getBoundingClientRect ? node.getBoundingClientRect() : { top: 0, bottom: 0 };
				fallbackReads.push({ node: node, top: rect.top, bottom: rect.bottom });
			}

			for (var d = 0; d < directReveals.length; d++) {
				if (revealNode(directReveals[d])) {
					revealed++;
				}
			}
			for (var j = 0; j < fallbackReads.length; j++) {
				var item = fallbackReads[j];
				if (item.top < viewportHeight * 2 && item.bottom > -viewportHeight && revealNode(item.node)) {
					revealed++;
				}
			}
			mark('elementor-bg-lazy-checked', checked);
			mark('elementor-bg-lazy-revealed', revealed);
			mark('elementor-bg-lazy-observed', observed);
			mark('elementor-bg-lazy-fallback-reads', fallbackReads.length);
		} catch (e) {
			mark('elementor-bg-lazy-error', '1');
		}
	}

	function schedule() {
		var scheduled = false;
		afterDomReady(revealElementorLazyBgs, 0);
		afterDomReady(revealElementorLazyBgs, 250);
		afterLoad(revealElementorLazyBgs, 0);

		function queue() {
			if (scheduled) {
				return;
			}
			scheduled = true;
			var callback = function () {
				scheduled = false;
				revealElementorLazyBgs();
			};
			if (window.requestAnimationFrame) {
				window.requestAnimationFrame(callback);
			} else {
				setTimeout(callback, 80);
			}
		}

		['scroll', 'resize', 'orientationchange', 'touchstart', 'pointerdown'].forEach(function (eventName) {
			window.addEventListener(eventName, queue, { passive: true });
		});
	}

	schedule();
}());
