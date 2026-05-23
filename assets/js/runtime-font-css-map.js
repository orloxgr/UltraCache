(function () {
    var cfg = window.ucwpRuntimeFontCssMapConfig || {};
    var map = cfg.map;
    if (!map || typeof map !== 'object') {
        map = {};
    }

    var maxLinks = 80;
    var seen = 0;

    var root = function () {
        return document.documentElement || document.body || document.head;
    };

    var mark = function (key, value) {
        try {
            var node = root();
            if (node) {
                node.setAttribute('data-ucwp-runtime-font-' + key, String(value));
            }
        } catch (e) {}
    };

    var toAbs = function (url) {
        if (!url) {
            return '';
        }
        try {
            return new URL(url, document.baseURI).href;
        } catch (e) {
            try {
                var a = document.createElement('a');
                a.href = url;
                return a.href || url;
            } catch (err) {
                return url;
            }
        }
    };

    var rewrite = function (node) {
        if (!node || node.nodeType !== 1 || seen >= maxLinks) {
            return;
        }
        var tag = String(node.tagName || '').toLowerCase();
        if (tag !== 'link') {
            return;
        }
        var rel = String(node.getAttribute('rel') || '').toLowerCase();
        if (rel.indexOf('stylesheet') === -1) {
            return;
        }
        seen++;
        var href = node.getAttribute('href') || node.href || '';
        if (!href) {
            return;
        }
        var abs = toAbs(href);
        if (abs && map[abs] && abs !== map[abs]) {
            node.setAttribute('href', map[abs]);
            node.setAttribute('data-ucwp-runtime-font-rewrite-hit', '1');
            try {
                node.href = map[abs];
            } catch (e) {}
        }
    };

    var scan = function (scanRoot) {
        try {
            var base = scanRoot && scanRoot.querySelectorAll ? scanRoot : document;
            var links = base.querySelectorAll ? base.querySelectorAll('link[rel][href]') : [];
            for (var i = 0; i < links.length && seen < maxLinks; i++) {
                rewrite(links[i]);
            }
            if (scanRoot && scanRoot.nodeType === 1) {
                rewrite(scanRoot);
            }
        } catch (e) {}
    };

    mark('css-map-count', cfg.count || Object.keys(map).length || 0);
    mark('css-map-source', cfg.source || 'empty');
    scan(document);

    try {
        var target = document.head || document.documentElement || document.body;
        var mo = new MutationObserver(function (list) {
            for (var i = 0; i < list.length && seen < maxLinks; i++) {
                var added = list[i] && list[i].addedNodes ? list[i].addedNodes : [];
                for (var j = 0; j < added.length && seen < maxLinks; j++) {
                    scan(added[j]);
                }
            }
        });
        if (target) {
            mo.observe(target, { childList: true, subtree: true });
            setTimeout(function () {
                try {
                    mo.disconnect();
                } catch (e) {}
            }, 10000);
        }
    } catch (e) {}
}());
