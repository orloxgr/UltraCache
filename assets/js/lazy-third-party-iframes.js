(function (window, document) {
	'use strict';

	var selector = 'iframe[data-ultracache-lazy-iframe="1"][data-ultracache-iframe-src]';
	var observer = null;
	var mutationObserver = null;

	function activate(frame) {
		if (!frame || frame.getAttribute('data-ultracache-iframe-activated') === '1') {
			return;
		}

		var source = frame.getAttribute('data-ultracache-iframe-src');
		if (!source) {
			return;
		}

		frame.setAttribute('data-ultracache-iframe-activated', '1');
		frame.setAttribute('src', source);
		frame.removeAttribute('data-ultracache-iframe-src');
		if (observer) {
			observer.unobserve(frame);
		}
	}

	function bindInteraction(frame) {
		if (!frame || frame.getAttribute('data-ultracache-iframe-interaction-bound') === '1') {
			return;
		}

		frame.setAttribute('data-ultracache-iframe-interaction-bound', '1');
		['pointerenter', 'mouseenter', 'focus'].forEach(function (eventName) {
			frame.addEventListener(eventName, function () {
				activate(frame);
			}, { once: true, passive: true });
		});

		function bindBlankDocument() {
			try {
				var frameDocument = frame.contentDocument;
				if (!frameDocument || frame.getAttribute('data-ultracache-iframe-blank-bound') === '1') {
					return;
				}
				frame.setAttribute('data-ultracache-iframe-blank-bound', '1');
				['pointerdown', 'touchstart', 'keydown', 'focusin'].forEach(function (eventName) {
					frameDocument.addEventListener(eventName, function () {
						activate(frame);
					}, { once: true, passive: eventName !== 'keydown' });
				});
			} catch (error) {
				// The blank placeholder may already have been activated cross-origin.
			}
		}

		frame.addEventListener('load', bindBlankDocument, { once: true });
		bindBlankDocument();
	}

	function observe(frame) {
		if (!frame || frame.getAttribute('data-ultracache-iframe-observed') === '1') {
			return;
		}

		frame.setAttribute('data-ultracache-iframe-observed', '1');
		bindInteraction(frame);
		if (observer) {
			observer.observe(frame);
		} else {
			activate(frame);
		}
	}

	function scan(root) {
		var scope = root && root.querySelectorAll ? root : document;
		if (scope.matches && scope.matches(selector)) {
			observe(scope);
		}
		Array.prototype.forEach.call(scope.querySelectorAll(selector), observe);
	}

	function start() {
		if ('IntersectionObserver' in window) {
			observer = new window.IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting || entry.intersectionRatio > 0) {
						activate(entry.target);
					}
				});
			}, {
				root: null,
				rootMargin: '400px 0px',
				threshold: 0.01
			});
		}

		scan(document);

		if ('MutationObserver' in window && document.documentElement) {
			mutationObserver = new window.MutationObserver(function (records) {
				records.forEach(function (record) {
					Array.prototype.forEach.call(record.addedNodes || [], function (node) {
						if (node && node.nodeType === 1) {
							scan(node);
						}
					});
				});
			});
			mutationObserver.observe(document.documentElement, { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start, { once: true });
	} else {
		start();
	}
}(window, document));
