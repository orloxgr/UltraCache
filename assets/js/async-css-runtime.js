(function (document) {
	'use strict';

	var selector = 'link[data-ultracache-async-css],link[data-ultracache-delayed-icon-fonts]';

	function isManagedStylesheet(node) {
		return Boolean(node && node.nodeType === 1 && typeof node.matches === 'function' && node.matches(selector));
	}

	function getTargetMedia(link) {
		var target = link.getAttribute('data-ultracache-target-media');
		target = typeof target === 'string' ? target.trim() : '';
		return target || 'all';
	}

	function activateStylesheet(link) {
		if (!isManagedStylesheet(link) || link.getAttribute('data-ultracache-async-css-activated') === '1') {
			return;
		}

		link.setAttribute('media', getTargetMedia(link));
		link.setAttribute('data-ultracache-async-css-activated', '1');
	}

	function prepareStylesheet(link) {
		if (!isManagedStylesheet(link)) {
			return;
		}

		if (link.sheet) {
			activateStylesheet(link);
		}
	}

	function scan(root) {
		if (!root) {
			return;
		}

		if (isManagedStylesheet(root)) {
			prepareStylesheet(root);
		}

		if (typeof root.querySelectorAll !== 'function') {
			return;
		}

		var links = root.querySelectorAll(selector);
		for (var index = 0; index < links.length; index++) {
			prepareStylesheet(links[index]);
		}
	}

	document.addEventListener('load', function (event) {
		activateStylesheet(event.target);
	}, true);

	scan(document);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			scan(document);
		}, { once: true });
	}

	if (typeof MutationObserver === 'function' && document.documentElement) {
		var observer = new MutationObserver(function (mutations) {
			for (var mutationIndex = 0; mutationIndex < mutations.length; mutationIndex++) {
				var addedNodes = mutations[mutationIndex].addedNodes || [];
				for (var nodeIndex = 0; nodeIndex < addedNodes.length; nodeIndex++) {
					scan(addedNodes[nodeIndex]);
				}
			}
		});
		observer.observe(document.documentElement, { childList: true, subtree: true });
	}
}(document));
